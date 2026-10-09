<?php

namespace App\Domain\Offboarding\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Models\User;
use App\Domain\Offboarding\Enums\RetentionPolicyStatus;
use App\Domain\Offboarding\Models\TenantRetentionTerm;
use App\Domain\Offboarding\Support\DataInventory;
use App\Domain\Offboarding\Support\OffboardingAudit;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Support\LifecycleRefused;
use App\Domain\Platform\Support\PlatformOperator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * SaaS.9 closure: C, the customer's contractual retention requirement, kept apart from the legal minimum (A) and
 * Markedge's company policy (B) in RetentionPolicies.
 *
 * - A term covers one category of one tenant, or all of its categories (`*`). It states what the contract requires:
 *   keep the data at least minimum_months after closure (it extends retention), and/or delete it within maximum_days of
 *   closure (it never deletes anything: a retention that runs past it is shown as a conflict for Markedge to resolve).
 * - Proposed by one platform operator with the contract reference, approved by another; decided terms never change (a
 *   new term is a new row). Lookup uses the category's own term, else the tenant's `*` term, and the longer minimum and
 *   the shorter deadline of the terms in force on the closure date and today.
 */
final class TenantRetentionTerms
{
    public function __construct(private readonly OffboardingAudit $audit, private readonly TenantContext $tenants) {}

    public function propose(Tenant $tenant, string $category, ?int $minimumMonths, ?int $maximumDays, string $contractReference, string $effectiveFrom,
        string $reason, ?User $actor): TenantRetentionTerm
    {
        $actor = PlatformOperator::assert($actor, 'record a customer\'s contractual retention');
        $reason = PlatformOperator::reason($reason);
        if ($category !== TenantRetentionTerm::ALL_CATEGORIES && ! array_key_exists($category, DataInventory::CATEGORIES)) {
            throw new InvalidArgumentException("Unknown data category [{$category}].");
        }
        if ($minimumMonths === null && $maximumDays === null) {
            throw new LifecycleRefused('A contractual term states a minimum retention, a deletion deadline, or both.');
        }
        if (($minimumMonths !== null && ($minimumMonths < 1 || $minimumMonths > 1200)) || ($maximumDays !== null && ($maximumDays < 1 || $maximumDays > 36500))) {
            throw new InvalidArgumentException('A minimum is 1 to 1,200 months; a deletion deadline is 1 to 36,500 days.');
        }
        if ($minimumMonths !== null && $maximumDays !== null && Carbon::parse('2001-01-01')->addMonthsNoOverflow($minimumMonths)->gt(Carbon::parse('2001-01-01')->addDays($maximumDays))) {
            throw new LifecycleRefused('This term contradicts itself: its minimum retention runs past its deletion deadline.');
        }
        $reference = trim($contractReference);
        if (mb_strlen($reference) < 3 || mb_strlen($reference) > 200) {
            throw new LifecycleRefused('The contract reference (agreement and clause) is required.');
        }
        $term = $this->tenants->runAs($tenant, fn () => TenantRetentionTerm::query()->create([
            'tenant_id' => $tenant->getKey(), 'category' => $category, 'minimum_months' => $minimumMonths, 'maximum_days' => $maximumDays,
            'contract_reference' => $reference, 'effective_from' => Carbon::parse($effectiveFrom)->toDateString(), 'status' => RetentionPolicyStatus::Pending,
            'proposed_by' => $actor?->getKey(), 'proposed_at' => now(), 'reason' => $reason,
        ]));
        $this->record(AuditAction::RetentionTermProposed, $tenant, $term, $reason, $actor);

        return $term;
    }

    public function approve(TenantRetentionTerm $term, ?User $actor, string $reason): TenantRetentionTerm
    {
        return $this->decide($term, $actor, $reason, RetentionPolicyStatus::Approved, AuditAction::RetentionTermApproved);
    }

    public function reject(TenantRetentionTerm $term, ?User $actor, string $reason): TenantRetentionTerm
    {
        return $this->decide($term, $actor, $reason, RetentionPolicyStatus::Rejected, AuditAction::RetentionTermRejected);
    }

    /** The proposer takes a pending term back. */
    public function withdraw(TenantRetentionTerm $term, ?User $actor, string $reason): TenantRetentionTerm
    {
        $actor = PlatformOperator::assert($actor, 'withdraw a contractual retention term');
        $reason = PlatformOperator::reason($reason);
        if ($actor !== null && (int) $term->proposed_by !== (int) $actor->getKey()) {
            throw new LifecycleRefused('Only the operator who proposed it can withdraw this term.');
        }

        return $this->transition($term, $actor, $reason, RetentionPolicyStatus::Withdrawn, AuditAction::RetentionTermWithdrawn);
    }

    /**
     * What the customer's contract requires for one category: the longest minimum and the shortest deadline of the
     * approved terms in force on the closure date and today (the category's own terms, else the tenant's `*` terms).
     *
     * @return array{minimum_months: ?int, maximum_days: ?int, references: list<string>}
     */
    public function termsFor(int $tenantId, string $category, Carbon $closedAt, ?Carbon $today = null): array
    {
        $today ??= today();
        $terms = collect([$closedAt->toDateString(), $today->toDateString()])
            ->flatMap(fn (string $day) => $this->inForce($tenantId, $category, $day))->unique('id');

        return [
            'minimum_months' => $terms->max('minimum_months'),
            'maximum_days' => $terms->whereNotNull('maximum_days')->min('maximum_days'),
            'references' => $terms->pluck('contract_reference')->unique()->values()->all(),
        ];
    }

    /** @return Collection<int, TenantRetentionTerm> every term of a tenant, newest first */
    public function forTenant(int $tenantId): Collection
    {
        return $this->tenants->bypass(fn () => TenantRetentionTerm::query()->where('tenant_id', $tenantId)->orderByDesc('id')->get());
    }

    public function find(int $id): ?TenantRetentionTerm
    {
        return $this->tenants->bypass(fn () => TenantRetentionTerm::query()->find($id));
    }

    /** @return Collection<int, TenantRetentionTerm> the approved terms in force on a day: the latest per category, its own before `*` */
    private function inForce(int $tenantId, string $category, string $day): Collection
    {
        foreach ([$category, TenantRetentionTerm::ALL_CATEGORIES] as $c) {
            $term = $this->tenants->bypass(fn () => TenantRetentionTerm::query()->where(['tenant_id' => $tenantId, 'category' => $c, 'status' => RetentionPolicyStatus::Approved->value])
                ->whereDate('effective_from', '<=', $day)->orderByDesc('effective_from')->orderByDesc('id')->first());
            if ($term !== null) {
                return collect([$term]);
            }
        }

        return collect();
    }

    private function decide(TenantRetentionTerm $term, ?User $actor, string $reason, RetentionPolicyStatus $to, AuditAction $action): TenantRetentionTerm
    {
        $actor = PlatformOperator::assert($actor, 'decide a contractual retention term');
        $reason = PlatformOperator::reason($reason);
        if ($actor !== null && (int) $term->proposed_by === (int) $actor->getKey()) {
            throw new LifecycleRefused('A contractual retention term is approved or rejected by an operator other than the one who proposed it.');
        }

        return $this->transition($term, $actor, $reason, $to, $action);
    }

    private function transition(TenantRetentionTerm $term, ?User $actor, string $reason, RetentionPolicyStatus $to, AuditAction $action): TenantRetentionTerm
    {
        $changed = $this->tenants->bypass(fn () => TenantRetentionTerm::query()->whereKey($term->getKey())->where('status', RetentionPolicyStatus::Pending->value)
            ->update(['status' => $to->value, 'decided_by' => $actor?->getKey(), 'decided_at' => now(), 'decision_reason' => $reason, 'updated_at' => now()]));
        if ($changed !== 1) {
            throw new LifecycleRefused('This term is no longer pending.');
        }
        $term = $this->find($term->getKey());
        $this->record($action, Tenant::query()->findOrFail($term->tenant_id), $term, $reason, $actor);

        return $term;
    }

    private function record(AuditAction $action, Tenant $tenant, TenantRetentionTerm $term, string $reason, ?User $actor): void
    {
        $this->audit->record($action, $tenant, $term, $reason, $actor, ['term' => $term->id, 'category' => $term->category, 'minimum_months' => $term->minimum_months,
            'maximum_days' => $term->maximum_days, 'contract_reference' => $term->contract_reference, 'effective_from' => $term->effective_from->toDateString(),
            'status' => $term->status->value]);
    }
}
