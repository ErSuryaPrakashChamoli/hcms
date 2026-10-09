<?php

namespace App\Domain\Offboarding\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Models\User;
use App\Domain\Offboarding\Enums\DeletionStatus;
use App\Domain\Offboarding\Enums\ExportStatus;
use App\Domain\Offboarding\Enums\OffboardingStatus;
use App\Domain\Offboarding\Jobs\ExecuteTenantDeletion;
use App\Domain\Offboarding\Models\TenantDeletion;
use App\Domain\Offboarding\Models\TenantDeletionStep;
use App\Domain\Offboarding\Models\TenantExport;
use App\Domain\Offboarding\Models\TenantOffboarding;
use App\Domain\Offboarding\Support\DataInventory;
use App\Domain\Offboarding\Support\OffboardingAudit;
use App\Domain\Offboarding\Support\SchemaGraph;
use App\Domain\Platform\Enums\TenantStatus;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Support\LifecycleRefused;
use App\Domain\Platform\Support\PlatformOperator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SaaS.9: a deletion of a closed tenant's eligible data, under maker-checker.
 *
 *   plan (maker: the reviewed plan, frozen with its fingerprint)
 *     → approve (a DIFFERENT operator; only if today's plan still has the same fingerprint; scheduled after the cooling-off)
 *     → in_progress (the worker, resumable step by step) → completed (certificate)
 *   reject (another operator) / cancel (before it starts) / failed → retry (resumes; every step re-checked)
 *
 * Nothing destructive happens in a web request, and nothing at all while `peopleos.offboarding.deletion_enabled` is off.
 * One deletion per tenant at a time (in_flight_key, unique): a second plan, or two at once, is refused.
 */
final class TenantDeletions
{
    public function __construct(
        private readonly DeletionPlanner $planner,
        private readonly OffboardingAudit $audit,
        private readonly TenantContext $tenants,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('peopleos.offboarding.deletion_enabled', false);
    }

    public function request(TenantOffboarding $case, ?User $actor, string $reason): TenantDeletion
    {
        $actor = PlatformOperator::assert($actor, 'plan a tenant deletion');
        $reason = PlatformOperator::reason($reason);
        $this->assertEnabled();
        $tenant = Tenant::query()->findOrFail($case->tenant_id);
        $plan = $this->planner->plan($tenant, $case);
        if ($plan['gates'] !== []) {
            throw new LifecycleRefused('Not deletable: '.implode(' ', array_column($plan['gates'], 'detail')));
        }
        if ($plan['eligible'] === []) {
            throw new LifecycleRefused('No category is eligible for deletion today.');
        }
        try {
            $deletion = DB::transaction(function () use ($tenant, $case, $plan, $actor, $reason) {
                // Under the exit lock: the tenant was not reopened and no hold changed since the plan was made.
                $this->assertUnchangedUnderLock($tenant, $case, $plan);

                return $this->tenants->runAs($tenant, fn () => TenantDeletion::query()->create([
                    'tenant_id' => $tenant->getKey(), 'offboarding_id' => $case->getKey(), 'reference' => (string) Str::ulid(), 'status' => DeletionStatus::Planned,
                    'in_flight_key' => $tenant->getKey(), 'plan' => $plan, 'plan_sha256' => DeletionPlanner::fingerprint($plan),
                    'requested_by' => $actor?->getKey(), 'requested_at' => now(), 'request_reason' => $reason,
                ]));
            });
        } catch (UniqueConstraintViolationException) {
            throw new LifecycleRefused('A deletion of this tenant is already planned, approved, running or failed.');
        }
        $this->audit->record(AuditAction::DeletionPlanned, $tenant, $deletion, $reason, $actor, ['deletion' => $deletion->reference, 'plan_sha256' => $deletion->plan_sha256,
            'eligible' => $plan['eligible'], 'final' => $plan['final']]);

        return $deletion;
    }

    /** The checker. Not the maker; only the plan as it was made; the deletion starts after the cooling-off. */
    public function approve(TenantDeletion $deletion, ?User $actor, string $reason): TenantDeletion
    {
        $actor = PlatformOperator::assert($actor, 'approve a tenant deletion');
        $reason = PlatformOperator::reason($reason);
        $this->assertEnabled();
        if ($actor !== null && (int) $deletion->requested_by === (int) $actor->getKey()) {
            throw new LifecycleRefused('A deletion is approved by an operator other than the one who planned it.');
        }
        $tenant = Tenant::query()->findOrFail($deletion->tenant_id);
        $case = $this->tenants->bypass(fn () => TenantOffboarding::query()->findOrFail($deletion->offboarding_id));
        $current = $this->planner->plan($tenant, $case, $deletion);
        if ($current['gates'] !== []) {
            throw new LifecycleRefused('Not deletable now: '.implode(' ', array_column($current['gates'], 'detail')));
        }
        if (DeletionPlanner::fingerprint($current) !== $deletion->plan_sha256) {
            throw new LifecycleRefused('The data, retention, holds or dependencies changed since this plan was made: reject it and plan again.');
        }

        $approved = DB::transaction(function () use ($deletion, $actor, $reason, $current, $tenant, $case) {
            // Under the exit lock: still closed, no hold placed or released and no export started since the check above.
            $this->assertUnchangedUnderLock($tenant, $case, $current);
            $rows = $this->tenants->bypass(fn () => TenantDeletion::query()->whereKey($deletion->getKey())->where('status', DeletionStatus::Planned->value)
                ->where('requested_by', '!=', $actor?->getKey() ?? 0)
                ->update(['status' => DeletionStatus::Approved->value, 'approved_by' => $actor?->getKey(), 'approved_at' => now(), 'approval_reason' => $reason,
                    'scheduled_for' => now()->addDays((int) config('peopleos.offboarding.cooling_off_days', 14)), 'updated_at' => now()]));
            if ($rows !== 1) {
                return false;
            }
            $this->createSteps($deletion, $current);

            return true;
        });
        if (! $approved) {
            throw new LifecycleRefused('This deletion is no longer waiting for approval.');
        }
        $deletion = $this->find($deletion->getKey());
        $this->audit->record(AuditAction::DeletionApproved, $tenant, $deletion, $reason, $actor, ['deletion' => $deletion->reference, 'plan_sha256' => $deletion->plan_sha256,
            'requested_by' => $deletion->requested_by, 'scheduled_for' => $deletion->scheduled_for?->toIso8601String()]);

        return $deletion;
    }

    public function reject(TenantDeletion $deletion, ?User $actor, string $reason): TenantDeletion
    {
        $actor = PlatformOperator::assert($actor, 'reject a tenant deletion');
        $reason = PlatformOperator::reason($reason);

        return $this->close($deletion, $actor, $reason, [DeletionStatus::Planned], DeletionStatus::Rejected,
            ['rejected_by' => $actor?->getKey(), 'rejected_at' => now(), 'rejection_reason' => $reason], AuditAction::DeletionRejected);
    }

    /** Before it starts (planned or approved, not yet running). */
    public function cancel(TenantDeletion $deletion, ?User $actor, string $reason): TenantDeletion
    {
        $actor = PlatformOperator::assert($actor, 'cancel a tenant deletion');
        $reason = PlatformOperator::reason($reason);

        return $this->close($deletion, $actor, $reason, [DeletionStatus::Planned, DeletionStatus::Approved], DeletionStatus::Cancelled,
            ['cancelled_by' => $actor?->getKey(), 'cancelled_at' => now(), 'cancel_reason' => $reason], AuditAction::DeletionCancelled);
    }

    /** A failed deletion resumes: the worker re-checks every remaining step (gates, holds, the plan) before it deletes. */
    public function retry(TenantDeletion $deletion, ?User $actor, string $reason): TenantDeletion
    {
        $actor = PlatformOperator::assert($actor, 'retry a tenant deletion');
        $reason = PlatformOperator::reason($reason);
        $this->assertEnabled();
        $changed = $this->tenants->bypass(fn () => TenantDeletion::query()->whereKey($deletion->getKey())->where('status', DeletionStatus::Failed->value)
            ->update(['status' => DeletionStatus::Approved->value, 'scheduled_for' => now(), 'failure' => null, 'failed_at' => null, 'updated_at' => now()]));
        if ($changed !== 1) {
            throw new LifecycleRefused('Only a failed deletion is retried.');
        }
        $this->tenants->bypass(fn () => TenantDeletionStep::query()->where('tenant_deletion_id', $deletion->getKey())->where('status', TenantDeletionStep::FAILED)
            ->update(['status' => TenantDeletionStep::PENDING, 'updated_at' => now()]));
        $deletion = $this->find($deletion->getKey());
        $this->audit->record(AuditAction::DeletionRetried, Tenant::query()->findOrFail($deletion->tenant_id), $deletion, $reason, $actor, ['deletion' => $deletion->reference]);
        ExecuteTenantDeletion::dispatch($deletion->getKey(), $deletion->tenant_id);

        return $deletion;
    }

    /**
     * The scheduler (peopleos:offboarding:tick): approved deletions whose cooling-off ended are dispatched; a running one
     * whose worker stopped is marked failed (an operator retries it, and it resumes).
     *
     * @return array{dispatched: int, stalled: int}
     */
    public function dispatchDue(): array
    {
        if (! self::enabled()) {
            return ['dispatched' => 0, 'stalled' => 0];
        }
        $due = $this->tenants->bypass(fn () => TenantDeletion::query()->where('status', DeletionStatus::Approved->value)->where('scheduled_for', '<=', now())->get());
        foreach ($due as $deletion) {
            ExecuteTenantDeletion::dispatch($deletion->getKey(), $deletion->tenant_id);
        }
        $lease = now()->subMinutes((int) config('peopleos.offboarding.deletion_lease_minutes', 30));
        $stalled = $this->tenants->bypass(fn () => TenantDeletion::query()->where('status', DeletionStatus::InProgress->value)->where('heartbeat_at', '<', $lease)
            ->update(['status' => DeletionStatus::Failed->value, 'failed_at' => now(), 'failure' => 'The deletion worker stopped; retry to resume.', 'updated_at' => now()]));

        return ['dispatched' => $due->count(), 'stalled' => $stalled];
    }

    /** An operator records what happened outside PeopleOS for an external dependency (requested, confirmed or unsupported). */
    public function recordExternal(TenantDeletionStep $step, string $status, ?User $actor, string $reason, ?string $retainedUntil = null): TenantDeletionStep
    {
        $actor = PlatformOperator::assert($actor, 'record an external deletion');
        $reason = PlatformOperator::reason($reason);
        if ($step->kind !== 'external' || ! in_array($status, TenantDeletionStep::EXTERNAL_RECORDABLE, true)) {
            throw new LifecycleRefused('Not an external dependency, or not a valid status.');
        }
        if ($status === TenantDeletionStep::EXTERNAL_RETENTION_REQUIRED && ($retainedUntil === null || Carbon::parse($retainedUntil)->lt(today()))) {
            throw new LifecycleRefused('A copy that must be kept is recorded with the date until which it is kept (today or later).');
        }
        $deletion = $this->find($step->tenant_deletion_id);
        if (! in_array($deletion->status, [DeletionStatus::Approved, DeletionStatus::InProgress, DeletionStatus::Failed, DeletionStatus::Completed], true)) {
            throw new LifecycleRefused('This deletion was not approved, or was cancelled: there is nothing to record.');
        }
        // Conditional on the status read: two operators recording at once make one change; a confirmation is final.
        $before = $this->tenants->bypass(fn () => TenantDeletionStep::query()->findOrFail($step->getKey()));
        $detail = array_merge($before->detail ?? [], ['recorded_by' => $actor?->getKey(), 'recorded_at' => now()->toIso8601String(), 'recorded_note' => $reason,
            'previous_status' => $before->status, 'retained_until' => $status === TenantDeletionStep::EXTERNAL_RETENTION_REQUIRED ? Carbon::parse($retainedUntil)->toDateString() : null]);
        $changed = $this->tenants->bypass(fn () => TenantDeletionStep::query()->whereKey($step->getKey())->where('kind', 'external')
            ->where('status', $before->status)->where('status', '!=', TenantDeletionStep::EXTERNAL_CONFIRMED)
            ->update(['status' => $status, 'detail' => json_encode($detail), 'updated_at' => now()]));
        if ($changed !== 1) {
            throw new LifecycleRefused('This external dependency is already confirmed, or was just recorded by someone else: reload.');
        }
        $step = $this->tenants->bypass(fn () => TenantDeletionStep::query()->findOrFail($step->getKey()));
        $this->audit->record(AuditAction::ExternalDeletionConfirmed, Tenant::query()->findOrFail($deletion->tenant_id), $deletion, $reason, $actor,
            ['deletion' => $deletion->reference, 'external' => $step->target, 'status' => $status]);

        return $step;
    }

    public function find(int $id): TenantDeletion
    {
        return $this->tenants->bypass(fn () => TenantDeletion::query()->findOrFail($id));
    }

    /**
     * The executable steps of an approved plan, in order: per category its files (paths come from the rows, so first) and
     * child rows; then the references that close a cycle cleared, and every table deleted children first (constraint
     * checks stay on; the audit chain takes its place in that order, deleted as a whole by its own step); then
     * anonymisation; then the tenant's stored files; then the verification that no reference dangles; then the external
     * dependencies.
     */
    private function createSteps(TenantDeletion $deletion, array $plan): void
    {
        $steps = [];
        $add = function (string $category, string $kind, string $target, ?int $count = null, string $status = TenantDeletionStep::PENDING, array $detail = []) use (&$steps) {
            $steps[] = compact('category', 'kind', 'target', 'count', 'status', 'detail');
        };
        $eligible = array_filter($plan['categories'], fn (array $c) => in_array($c['action'], [DataInventory::DELETE, DataInventory::ANONYMISE], true));
        $deleteTables = [];
        $tableCategory = DataInventory::tableCategories();
        foreach ($eligible as $category => $c) {
            if ($category === 'audit') {
                continue;
            }
            foreach (array_keys(DataInventory::FILES) as $table) {
                if (($tableCategory[$table] ?? null) === $category && ! in_array($table, $c['anonymised_tables'], true)) {
                    $add($category, 'files', $table, $c['files']);
                }
            }
            foreach (DataInventory::childrenOf($category) as $child) {
                $add($category, 'children', $child, $c['tables'][$child] ?? null);
            }
            foreach (DataInventory::TABLES[$category] as $table) {
                if (! in_array($table, $c['anonymised_tables'], true)) {
                    $deleteTables[] = $table;
                }
            }
        }
        // The audit chain is ordered like any table (before the accounts it names, so no actor reference is nulled on a
        // chain row that still exists), but deleted by its own step (chain lock, final hash, newest first).
        if (isset($eligible['audit'])) {
            $deleteTables[] = 'audit_events';
        }
        foreach (SchemaGraph::nullify($deleteTables) as $ref) {
            $add($tableCategory[$ref['child']], 'nullify', $ref['child'].'.'.$ref['column']);
        }
        foreach (SchemaGraph::deletionOrder($deleteTables) as $table) {
            if ($table === 'audit_events') {
                $add('audit', 'audit', 'audit_events', $eligible['audit']['tables']['audit_events'] ?? null);

                continue;
            }
            $add($tableCategory[$table], 'rows', $table, $eligible[$tableCategory[$table]]['tables'][$table] ?? null);
        }
        foreach ($eligible as $category => $c) {
            foreach ($c['anonymised_tables'] as $table) {
                $add($category, 'anonymise', $table, $c['tables'][$table] ?? null);
            }
        }
        $tenant = Tenant::query()->findOrFail($deletion->tenant_id);
        foreach ($eligible as $category => $c) {
            foreach (DataInventory::prefixes($tenant)[$category] ?? [] as $prefix) {
                $add($category, 'stored', $prefix['disk'].'::'.$prefix['prefix'], null);
            }
        }
        $add('verification', 'verify', 'references');
        foreach ($this->externals($tenant) as $external) {
            $add('external', 'external', $external['target'], null, $external['status'], ['note' => $external['note']]);
        }

        foreach ($steps as $position => $step) {
            TenantDeletionStep::query()->withoutTenancy()->create([
                'tenant_deletion_id' => $deletion->getKey(), 'tenant_id' => $deletion->tenant_id, 'position' => $position + 1, 'category' => $step['category'],
                'kind' => $step['kind'], 'target' => $step['target'], 'status' => $step['status'], 'planned_count' => $step['count'],
                'detail' => $step['detail'] === [] ? null : $step['detail'],
            ]);
        }
    }

    /**
     * Where the tenant's data also lives outside PeopleOS. PeopleOS cannot delete it there and never claims it did: each
     * is recorded as required (an operator records the request and the confirmation) or unsupported.
     *
     * @return list<array{target: string, status: string, note: string}>
     */
    private function externals(Tenant $tenant): array
    {
        $out = [
            ['target' => 'database backups', 'status' => TenantDeletionStep::EXTERNAL_REQUIRED,
                'note' => 'Database backups and provider snapshots taken before the deletion still contain the data until they age out under the backup schedule; a restore must re-apply this deletion (tenant id and deletion reference).'],
            ['target' => 'file storage backups and replicas', 'status' => TenantDeletionStep::EXTERNAL_REQUIRED,
                'note' => 'Copies of the tenant\'s files in storage backups, object versions or replicas outlive the deletion of the files themselves until they expire or are purged.'],
        ];
        $systems = DB::table('integration_systems')->where('tenant_id', $tenant->getKey())->pluck('name')->all();
        foreach ($systems as $name) {
            $out[] = ['target' => 'integration: '.$name, 'status' => TenantDeletionStep::EXTERNAL_REQUIRED,
                'note' => 'Data PeopleOS exchanged with this external system stays there: the customer (or Markedge, by contract) asks that system to delete it.'];
        }
        if (DB::table('webhook_endpoints')->where('tenant_id', $tenant->getKey())->exists()) {
            $out[] = ['target' => 'webhook receivers', 'status' => TenantDeletionStep::EXTERNAL_UNSUPPORTED,
                'note' => 'Events delivered to the customer\'s own webhook endpoints are in the customer\'s systems.'];
        }
        if (DB::table('payments')->where('tenant_id', $tenant->getKey())->exists()) {
            $out[] = ['target' => 'payment provider', 'status' => TenantDeletionStep::EXTERNAL_UNSUPPORTED,
                'note' => 'Payment records at the provider are Markedge\'s financial records, retained like the invoices and payments in PeopleOS.'];
        }
        $out[] = ['target' => 'e-mail delivered', 'status' => TenantDeletionStep::EXTERNAL_UNSUPPORTED,
            'note' => 'Notifications already e-mailed are in the recipients\' mailboxes and the mail provider\'s logs.'];

        return $out;
    }

    private function close(TenantDeletion $deletion, ?User $actor, string $reason, array $from, DeletionStatus $to, array $fields, AuditAction $action): TenantDeletion
    {
        $changed = $this->tenants->bypass(fn () => TenantDeletion::query()->whereKey($deletion->getKey())->whereIn('status', array_map(fn (DeletionStatus $s) => $s->value, $from))
            ->update(['status' => $to->value, 'in_flight_key' => null, 'updated_at' => now()] + $fields));
        if ($changed !== 1) {
            throw new LifecycleRefused('This deletion can no longer be '.$to->value.'.');
        }
        $deletion = $this->find($deletion->getKey());
        $this->audit->record($action, Tenant::query()->findOrFail($deletion->tenant_id), $deletion, $reason, $actor, ['deletion' => $deletion->reference]);

        return $deletion;
    }

    /**
     * Inside the transaction, under the exit lock (LegalHolds::lockFor): the case is still the tenant's open, closed case,
     * the tenant is still closed, the active holds are exactly those the plan considered, and no export is generating.
     * A reopen, a hold or an export request that committed meanwhile refuses the change instead of racing it.
     */
    private function assertUnchangedUnderLock(Tenant $tenant, TenantOffboarding $case, array $plan): void
    {
        $locked = LegalHolds::lockFor($tenant->getKey());
        if ($locked === null || $locked->getKey() !== $case->getKey() || $locked->status !== OffboardingStatus::Closed
            || Tenant::query()->whereKey($tenant->getKey())->value('status') !== TenantStatus::Closed) {
            throw new LifecycleRefused('The tenant was reopened or changed meanwhile: nothing was changed.');
        }
        $holds = array_map(fn ($hold) => $hold->reference, app(LegalHolds::class)->holding($tenant->getKey()));
        sort($holds);
        if ($holds !== DeletionPlanner::holdReferences($plan)) {
            throw new LifecycleRefused('A legal hold was placed or released meanwhile: plan again.');
        }
        if ($this->tenants->bypass(fn () => TenantExport::query()->where('tenant_id', $tenant->getKey())->whereIn('status', ExportStatus::inFlightValues())->exists())) {
            throw new LifecycleRefused('An export of this tenant is being generated: wait until it is ready.');
        }
    }

    private function assertEnabled(): void
    {
        if (! self::enabled()) {
            throw new LifecycleRefused('Tenant deletion is switched off in this environment (PEOPLEOS_TENANT_DELETION_ENABLED).');
        }
    }
}
