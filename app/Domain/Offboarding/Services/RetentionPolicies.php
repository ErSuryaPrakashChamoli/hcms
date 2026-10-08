<?php

namespace App\Domain\Offboarding\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Offboarding\Enums\RetentionPolicyStatus;
use App\Domain\Offboarding\Models\RetentionPolicy;
use App\Domain\Offboarding\Support\DataInventory;
use App\Domain\Platform\Support\LifecycleRefused;
use App\Domain\Platform\Support\PlatformOperator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * SaaS.9 (decision D-8 as configuration): how long each category of a tenant's data is kept after the tenant closes, per
 * jurisdiction. Nothing here is a legal value: every period is entered by an operator with its basis, and approved by a
 * second operator before it applies. PeopleOS ships with no policy, so nothing is deletable until Markedge decides.
 *
 * - A version has an optional legal minimum (with its legal reference) and a company policy period that may never be
 *   below it. Without a legal minimum the version says LEGAL_MINIMUM_NOT_CONFIGURED: none is assumed.
 * - Versions are effective-dated, approved by someone other than the proposer, and refused once their date has passed
 *   unapproved (propose again). History never changes: a new rule is a new version.
 * - Lookup: the tenant's own jurisdiction (its country), else the `*` policy for every jurisdiction without its own.
 * - A tenant's retention for a category runs from its closure for the LONGER of the version in force on the closure date
 *   and the version in force today: a later, shorter policy never makes data deletable earlier than the policy that
 *   applied when the tenant left, and a later, longer one keeps it longer.
 */
final class RetentionPolicies
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function propose(string $category, string $jurisdiction, ?int $legalMinimumMonths, ?string $legalBasis, ?string $legalReference,
        int $policyMonths, ?string $policyBasis, string $effectiveFrom, string $reason, ?User $actor): RetentionPolicy
    {
        $actor = PlatformOperator::assert($actor, 'propose a retention policy');
        $reason = PlatformOperator::reason($reason);
        if (! array_key_exists($category, DataInventory::CATEGORIES)) {
            throw new InvalidArgumentException("Unknown data category [{$category}].");
        }
        $jurisdiction = strtoupper(trim($jurisdiction));
        if ($jurisdiction !== RetentionPolicy::ALL_JURISDICTIONS && preg_match('/^[A-Z]{2}$/', $jurisdiction) !== 1) {
            throw new InvalidArgumentException('The jurisdiction is a two-letter country code, or * for every jurisdiction without its own policy.');
        }
        if ($policyMonths < 0 || $policyMonths > 1200 || ($legalMinimumMonths !== null && ($legalMinimumMonths < 0 || $legalMinimumMonths > 1200))) {
            throw new InvalidArgumentException('Retention is between 0 and 1,200 months.');
        }
        if ($legalMinimumMonths !== null) {
            if (blank($legalReference) || blank($legalBasis)) {
                throw new LifecycleRefused('A legal minimum needs its legal basis and reference.');
            }
            if ($policyMonths < $legalMinimumMonths) {
                throw new LifecycleRefused("The company policy ({$policyMonths} months) cannot be below the legal minimum ({$legalMinimumMonths} months).");
            }
        }
        $from = Carbon::parse($effectiveFrom)->startOfDay();
        if ($from->lt(today())) {
            throw new LifecycleRefused('A retention policy starts today or later.');
        }

        $policy = DB::transaction(function () use ($category, $jurisdiction, $legalMinimumMonths, $legalBasis, $legalReference, $policyMonths, $policyBasis, $from, $reason, $actor) {
            $version = (int) RetentionPolicy::query()->where(['category' => $category, 'jurisdiction' => $jurisdiction])->lockForUpdate()->max('version') + 1;

            return RetentionPolicy::query()->create([
                'category' => $category, 'jurisdiction' => $jurisdiction, 'version' => $version,
                'legal_minimum_months' => $legalMinimumMonths, 'legal_basis' => $legalBasis, 'legal_reference' => $legalReference,
                'policy_months' => $policyMonths, 'policy_basis' => $policyBasis, 'effective_from' => $from->toDateString(),
                'status' => RetentionPolicyStatus::Pending, 'proposed_by' => $actor?->getKey(), 'proposed_at' => now(), 'reason' => $reason,
            ]);
        }, 3); // MySQL: two first proposals of one category can deadlock on the empty range; retried

        $this->record(AuditAction::RetentionPolicyProposed, $policy, $reason, $actor);

        return $policy;
    }

    public function approve(RetentionPolicy $policy, ?User $actor, string $reason): RetentionPolicy
    {
        return $this->decide($policy, $actor, $reason, RetentionPolicyStatus::Approved, AuditAction::RetentionPolicyApproved);
    }

    public function reject(RetentionPolicy $policy, ?User $actor, string $reason): RetentionPolicy
    {
        return $this->decide($policy, $actor, $reason, RetentionPolicyStatus::Rejected, AuditAction::RetentionPolicyRejected);
    }

    /** The proposer takes a pending version back. */
    public function withdraw(RetentionPolicy $policy, ?User $actor, string $reason): RetentionPolicy
    {
        $actor = PlatformOperator::assert($actor, 'withdraw a retention policy');
        $reason = PlatformOperator::reason($reason);
        if ($actor !== null && (int) $policy->proposed_by !== (int) $actor->getKey()) {
            throw new LifecycleRefused('Only the operator who proposed it can withdraw this version.');
        }
        $changed = RetentionPolicy::query()->whereKey($policy->getKey())->where('status', RetentionPolicyStatus::Pending->value)
            ->update(['status' => RetentionPolicyStatus::Withdrawn->value, 'decided_by' => $actor?->getKey(), 'decided_at' => now(), 'decision_reason' => $reason, 'updated_at' => now()]);
        if ($changed !== 1) {
            throw new LifecycleRefused('This version is no longer pending.');
        }
        $policy->refresh();
        $this->record(AuditAction::RetentionPolicyWithdrawn, $policy, $reason, $actor);

        return $policy;
    }

    /** The approved version in force for a category in a jurisdiction on a day (the jurisdiction's own, else `*`). */
    public function inForce(string $category, string $jurisdiction, string $day): ?RetentionPolicy
    {
        foreach ([strtoupper($jurisdiction), RetentionPolicy::ALL_JURISDICTIONS] as $j) {
            $policy = RetentionPolicy::query()->where(['category' => $category, 'jurisdiction' => $j, 'status' => RetentionPolicyStatus::Approved->value])
                ->whereDate('effective_from', '<=', $day)->orderByDesc('effective_from')->orderByDesc('version')->first();
            if ($policy !== null) {
                return $policy;
            }
        }

        return null;
    }

    /**
     * How long a closed tenant's category is kept: from the closure date, for the longer of the versions in force on the
     * closure date and today.
     *
     * @return array{configured: bool, months: ?int, until: ?string, policies: list<array{id: int, version: int, jurisdiction: string, months: int, legal_minimum_months: ?int}>, legal_minimum: string}
     */
    public function retentionFor(string $category, string $jurisdiction, Carbon $closedAt, ?Carbon $today = null): array
    {
        $today ??= today();
        $versions = array_values(array_filter([
            $this->inForce($category, $jurisdiction, $closedAt->toDateString()),
            $this->inForce($category, $jurisdiction, $today->toDateString()),
        ]));
        if ($versions === []) {
            return ['configured' => false, 'months' => null, 'until' => null, 'policies' => [], 'legal_minimum' => RetentionPolicy::LEGAL_MINIMUM_NOT_CONFIGURED];
        }
        $months = max(array_map(fn (RetentionPolicy $p) => $p->policy_months, $versions));
        $legal = array_filter(array_map(fn (RetentionPolicy $p) => $p->legal_minimum_months, $versions), fn ($m) => $m !== null);

        return [
            'configured' => true,
            'months' => $months,
            'until' => $closedAt->copy()->startOfDay()->addMonthsNoOverflow($months)->toDateString(),
            'policies' => array_values(array_map(fn (RetentionPolicy $p) => ['id' => $p->id, 'version' => $p->version, 'jurisdiction' => $p->jurisdiction,
                'months' => $p->policy_months, 'legal_minimum_months' => $p->legal_minimum_months], collect($versions)->unique('id')->values()->all())),
            'legal_minimum' => $legal === [] ? RetentionPolicy::LEGAL_MINIMUM_NOT_CONFIGURED : max($legal).' months',
        ];
    }

    private function decide(RetentionPolicy $policy, ?User $actor, string $reason, RetentionPolicyStatus $to, AuditAction $action): RetentionPolicy
    {
        $actor = PlatformOperator::assert($actor, 'decide a retention policy');
        $reason = PlatformOperator::reason($reason);
        if ($actor !== null && (int) $policy->proposed_by === (int) $actor->getKey()) {
            throw new LifecycleRefused('A retention policy is approved or rejected by an operator other than the one who proposed it.');
        }
        if ($to === RetentionPolicyStatus::Approved && $policy->effective_from->lt(today())) {
            throw new LifecycleRefused('This version was to start before today; propose it again from today or later.');
        }
        $changed = RetentionPolicy::query()->whereKey($policy->getKey())->where('status', RetentionPolicyStatus::Pending->value)
            ->update(['status' => $to->value, 'decided_by' => $actor?->getKey(), 'decided_at' => now(), 'decision_reason' => $reason, 'updated_at' => now()]);
        if ($changed !== 1) {
            throw new LifecycleRefused('This version is no longer pending.');
        }
        $policy->refresh();
        $this->record($action, $policy, $reason, $actor);

        return $policy;
    }

    private function record(AuditAction $action, RetentionPolicy $policy, string $reason, ?User $actor): void
    {
        $this->audit->record($action, 'offboarding', null, [], $reason, entityLabel: "Retention policy {$policy->category} / {$policy->jurisdiction} v{$policy->version}", actor: $actor,
            metadata: ['category' => $policy->category, 'jurisdiction' => $policy->jurisdiction, 'version' => $policy->version, 'policy_months' => $policy->policy_months,
                'legal_minimum_months' => $policy->legal_minimum_months, 'legal_reference' => $policy->legal_reference, 'effective_from' => $policy->effective_from->toDateString(),
                'status' => $policy->status->value], platform: true);
    }
}
