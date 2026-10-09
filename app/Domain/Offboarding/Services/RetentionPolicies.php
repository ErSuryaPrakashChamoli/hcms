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
 *   below it. Without a legal minimum the version says LEGAL_MINIMUM_NOT_CONFIGURED: none is assumed. An entered minimum
 *   is verified only with its authoritative source, reviewer and verification date; without them it is pending
 *   verification, and a pending minimum keeps the category from being deleted (it is never a silent floor or a zero).
 * - The company policy counts from its start event: the tenant's closure, or the end of the financial year in which the
 *   tenant closed (for periods the law counts from a year end).
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
        int $policyMonths, ?string $policyBasis, string $effectiveFrom, string $reason, ?User $actor,
        ?string $legalSource = null, ?string $legalVerifiedBy = null, ?string $legalVerifiedOn = null,
        string $startEvent = RetentionPolicy::START_TENANT_CLOSURE, ?string $financialYearEnd = null): RetentionPolicy
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
        $verification = array_filter([$legalSource, $legalVerifiedBy, $legalVerifiedOn], fn ($v) => filled($v));
        if ($legalMinimumMonths === null && $verification !== []) {
            throw new LifecycleRefused('A source, reviewer and verification date belong to a legal minimum; there is none in this version.');
        }
        if ($verification !== [] && count($verification) !== 3) {
            throw new LifecycleRefused('A verified legal minimum needs its authoritative source, its reviewer and the verification date (leave all three empty to record it as pending verification).');
        }
        if ($legalVerifiedOn !== null && Carbon::parse($legalVerifiedOn)->isFuture()) {
            throw new LifecycleRefused('A legal minimum cannot be verified on a future date.');
        }
        $legalStatus = $legalMinimumMonths === null ? RetentionPolicy::LEGAL_NOT_CONFIGURED
            : (count($verification) === 3 ? RetentionPolicy::LEGAL_VERIFIED : RetentionPolicy::LEGAL_PENDING_VERIFICATION);
        if (! array_key_exists($startEvent, RetentionPolicy::START_EVENTS)) {
            throw new InvalidArgumentException("Unknown retention start event [{$startEvent}].");
        }
        if ($startEvent === RetentionPolicy::START_FINANCIAL_YEAR_END) {
            $financialYearEnd ??= '03-31';
            if (preg_match('/^(\d{2})-(\d{2})$/', $financialYearEnd, $md) !== 1 || ! checkdate((int) $md[1], (int) $md[2], 2001)) {
                throw new InvalidArgumentException('The financial year end is a month and day, MM-DD (for example 03-31).');
            }
        } else {
            $financialYearEnd = null;
        }
        $from = Carbon::parse($effectiveFrom)->startOfDay();
        if ($from->lt(today())) {
            throw new LifecycleRefused('A retention policy starts today or later.');
        }

        $policy = DB::transaction(function () use ($category, $jurisdiction, $legalMinimumMonths, $legalBasis, $legalReference, $policyMonths, $policyBasis, $from, $reason, $actor,
            $legalStatus, $legalSource, $legalVerifiedBy, $legalVerifiedOn, $startEvent, $financialYearEnd) {
            $version = (int) RetentionPolicy::query()->where(['category' => $category, 'jurisdiction' => $jurisdiction])->lockForUpdate()->max('version') + 1;

            return RetentionPolicy::query()->create([
                'category' => $category, 'jurisdiction' => $jurisdiction, 'version' => $version,
                'legal_minimum_months' => $legalMinimumMonths, 'legal_minimum_status' => $legalStatus, 'legal_basis' => $legalBasis, 'legal_reference' => $legalReference,
                'legal_source' => $legalMinimumMonths === null ? null : $legalSource, 'legal_verified_by' => $legalMinimumMonths === null ? null : $legalVerifiedBy,
                'legal_verified_on' => $legalMinimumMonths === null ? null : $legalVerifiedOn,
                'policy_months' => $policyMonths, 'policy_basis' => $policyBasis, 'start_event' => $startEvent, 'financial_year_end' => $financialYearEnd,
                'effective_from' => $from->toDateString(),
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
     * How long a closed tenant's category is kept under Markedge's policy: each version's period counted from its start
     * event (the closure, or the end of the financial year in which the tenant closed), for the longer of the versions in
     * force on the closure date and today. The legal minimum is reported with its verification state.
     *
     * @return array{configured: bool, months: ?int, until: ?string, start_event: ?string, policies: list<array<string, mixed>>, legal_minimum: string, legal_minimum_status: string}
     */
    public function retentionFor(string $category, string $jurisdiction, Carbon $closedAt, ?Carbon $today = null): array
    {
        $today ??= today();
        $versions = collect([
            $this->inForce($category, $jurisdiction, $closedAt->toDateString()),
            $this->inForce($category, $jurisdiction, $today->toDateString()),
        ])->filter()->unique('id')->values();
        if ($versions->isEmpty()) {
            return ['configured' => false, 'months' => null, 'until' => null, 'start_event' => null, 'policies' => [],
                'legal_minimum' => RetentionPolicy::LEGAL_MINIMUM_NOT_CONFIGURED, 'legal_minimum_status' => RetentionPolicy::LEGAL_NOT_CONFIGURED];
        }
        $governing = $versions->sortByDesc(fn (RetentionPolicy $p) => $this->until($p, $closedAt)->toDateString())->first();
        $legal = $versions->filter(fn (RetentionPolicy $p) => $p->legal_minimum_months !== null);
        $pending = $legal->contains(fn (RetentionPolicy $p) => $p->legal_minimum_status === RetentionPolicy::LEGAL_PENDING_VERIFICATION);
        $legalStatus = $legal->isEmpty() ? RetentionPolicy::LEGAL_NOT_CONFIGURED : ($pending ? RetentionPolicy::LEGAL_PENDING_VERIFICATION : RetentionPolicy::LEGAL_VERIFIED);

        return [
            'configured' => true,
            'months' => $governing->policy_months,
            'until' => $this->until($governing, $closedAt)->toDateString(),
            'start_event' => $governing->start_event,
            'policies' => $versions->map(fn (RetentionPolicy $p) => ['id' => $p->id, 'version' => $p->version, 'jurisdiction' => $p->jurisdiction,
                'months' => $p->policy_months, 'start_event' => $p->start_event, 'legal_minimum_months' => $p->legal_minimum_months,
                'legal_minimum_status' => $p->legal_minimum_status])->all(),
            'legal_minimum' => match ($legalStatus) {
                RetentionPolicy::LEGAL_NOT_CONFIGURED => RetentionPolicy::LEGAL_MINIMUM_NOT_CONFIGURED,
                RetentionPolicy::LEGAL_PENDING_VERIFICATION => RetentionPolicy::LEGAL_MINIMUM_PENDING_VERIFICATION,
                default => $legal->max('legal_minimum_months').' months (verified)',
            },
            'legal_minimum_status' => $legalStatus,
        ];
    }

    /** The day a version's period starts for a tenant that closed on $closedAt. */
    public function startDate(RetentionPolicy $policy, Carbon $closedAt): Carbon
    {
        $closed = $closedAt->copy()->startOfDay();
        if ($policy->start_event !== RetentionPolicy::START_FINANCIAL_YEAR_END) {
            return $closed;
        }
        [$month, $day] = array_map('intval', explode('-', (string) ($policy->financial_year_end ?? '03-31')));
        $yearEnd = $closed->copy()->setDate($closed->year, $month, $day);

        return $yearEnd->lt($closed) ? $yearEnd->addYearNoOverflow() : $yearEnd;
    }

    private function until(RetentionPolicy $policy, Carbon $closedAt): Carbon
    {
        return $this->startDate($policy, $closedAt)->addMonthsNoOverflow($policy->policy_months);
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
                'start_event' => $policy->start_event, 'legal_minimum_months' => $policy->legal_minimum_months, 'legal_minimum_status' => $policy->legal_minimum_status,
                'legal_reference' => $policy->legal_reference, 'legal_source' => $policy->legal_source, 'legal_verified_by' => $policy->legal_verified_by,
                'legal_verified_on' => $policy->legal_verified_on?->toDateString(), 'effective_from' => $policy->effective_from->toDateString(),
                'status' => $policy->status->value], platform: true);
    }
}
