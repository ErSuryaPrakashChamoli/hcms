<?php

namespace App\Domain\Offboarding\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Entitlements\Services\TenantCommercialLock;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\SessionSecurity;
use App\Domain\Offboarding\Enums\DeletionStatus;
use App\Domain\Offboarding\Enums\OffboardingStatus;
use App\Domain\Offboarding\Models\TenantDeletion;
use App\Domain\Offboarding\Models\TenantOffboarding;
use App\Domain\Offboarding\Support\OffboardingAudit;
use App\Domain\Platform\Enums\TenantStatus;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Platform\Support\LifecycleRefused;
use App\Domain\Platform\Support\PlatformOperator;
use App\Domain\Subscriptions\Services\SubscriptionDirectory;
use App\Support\Tenancy\Jobs\DeferredTenantJobs;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SaaS.9: a tenant's exit from PeopleOS, as one case.
 *
 *   request (the tenant keeps working; exports can be generated) ── cancel
 *      │
 *   close (end of service: nobody signs in; queued work waits; the warehouse feed stops; retention starts) ── reopen
 *      │                                                     (until a deletion is approved or started)
 *   deletion(s) of eligible data (TenantDeletions: maker-checker, cooling-off, holds, retention) → completed
 *
 * Every step: a platform operator (in the service), a reason, both audit chains, and a conditional update so two
 * operators acting at once make one transition. Closing never ends a subscription or settles an invoice: an operator
 * ends the subscription through the subscription lifecycle first, and open financial items are shown, not cleared.
 * A tenant's exit is not its employees' exit: no employee lifecycle state changes.
 */
final class TenantOffboardings
{
    public function __construct(
        private readonly OffboardingAudit $audit,
        private readonly TenantContext $tenants,
        private readonly SessionSecurity $sessions,
        private readonly SubscriptionDirectory $subscriptions,
    ) {}

    public function openCase(Tenant $tenant): ?TenantOffboarding
    {
        return $this->tenants->bypass(fn () => TenantOffboarding::query()->where('open_key', $tenant->getKey())->first());
    }

    public function request(Tenant $tenant, string $reason, ?string $customerReference, ?string $serviceEndDate, ?User $actor): TenantOffboarding
    {
        $actor = PlatformOperator::assert($actor, 'start a tenant\'s offboarding');
        $reason = PlatformOperator::reason($reason);
        $tenant = Tenant::query()->findOrFail($tenant->getKey());
        if (! in_array($tenant->status, [TenantStatus::Trial, TenantStatus::Active, TenantStatus::Suspended], true)) {
            throw new LifecycleRefused('Only a tenant in use or suspended can start an offboarding.');
        }
        try {
            $case = $this->tenants->runAs($tenant, fn () => TenantOffboarding::query()->create([
                'tenant_id' => $tenant->getKey(), 'reference' => (string) Str::ulid(), 'status' => OffboardingStatus::Requested,
                'open_key' => $tenant->getKey(), 'reason' => $reason, 'customer_reference' => $customerReference,
                'service_end_date' => $serviceEndDate === null || $serviceEndDate === '' ? null : Carbon::parse($serviceEndDate)->toDateString(),
                'requested_by' => $actor?->getKey(), 'requested_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            throw new LifecycleRefused('This tenant already has an open offboarding.');
        }
        $this->audit->record(AuditAction::OffboardingRequested, $tenant, $case, $reason, $actor,
            ['offboarding' => $case->reference, 'customer_reference' => $customerReference, 'service_end_date' => $case->service_end_date?->toDateString()]);

        return $case;
    }

    /** Before the tenant is closed: the customer stays. */
    public function cancel(TenantOffboarding $case, ?User $actor, string $reason): TenantOffboarding
    {
        $actor = PlatformOperator::assert($actor, 'cancel an offboarding');
        $reason = PlatformOperator::reason($reason);
        $changed = $this->tenants->bypass(fn () => TenantOffboarding::query()->whereKey($case->getKey())->where('status', OffboardingStatus::Requested->value)
            ->update(['status' => OffboardingStatus::Cancelled->value, 'open_key' => null, 'cancelled_by' => $actor?->getKey(), 'cancelled_at' => now(),
                'cancel_reason' => $reason, 'updated_at' => now()]));
        if ($changed !== 1) {
            throw new LifecycleRefused('Only a requested offboarding can be cancelled (a closed tenant is reopened instead).');
        }
        $case = $this->fresh($case);
        $this->audit->record(AuditAction::OffboardingCancelled, $this->tenantOf($case), $case, $reason, $actor, ['offboarding' => $case->reference]);

        return $case;
    }

    /** @return list<array{code: string, detail: string}> what stops the tenant from being closed */
    public function closureBlockers(Tenant $tenant): array
    {
        return array_map(fn (array $s) => ['code' => 'SUBSCRIPTION_CONTINUES', 'detail' => "Subscription #{$s['subscription_id']} is {$s['status']}".($s['until'] ? " until {$s['until']}" : ' with no end').': end it on Subscriptions first'],
            $this->subscriptions->continuing($tenant));
    }

    public function close(TenantOffboarding $case, ?User $actor, string $reason): TenantOffboarding
    {
        $actor = PlatformOperator::assert($actor, 'close a tenant');
        $reason = PlatformOperator::reason($reason);
        $tenant = $this->tenantOf($case);
        $settings = app(SettingsRepository::class);
        $warehouse = (bool) $this->tenants->runAs($tenant, fn () => $settings->get('warehouse.export_enabled', false));

        // Under the commercial lock (the one every subscription change takes) and then the exit lock: a subscription
        // started, extended or reactivated meanwhile is seen here, and none can start once the tenant is closed.
        $before = app(TenantCommercialLock::class)->run($tenant, fn () => DB::transaction(function () use ($case, $tenant, $actor, $warehouse) {
            LegalHolds::lockFor($tenant->getKey());
            if (($blockers = $this->closureBlockers($tenant)) !== []) {
                throw new LifecycleRefused(implode(' ', array_column($blockers, 'detail')));
            }
            $before = Tenant::query()->whereKey($tenant->getKey())->value('status');
            $before = $before instanceof TenantStatus ? $before : TenantStatus::tryFrom((string) $before);
            if (! in_array($before, [TenantStatus::Trial, TenantStatus::Active, TenantStatus::Suspended], true)) {
                return null;
            }
            $closed = $this->tenants->bypass(fn () => TenantOffboarding::query()->whereKey($case->getKey())->where('status', OffboardingStatus::Requested->value)
                ->update(['status' => OffboardingStatus::Closed->value, 'closed_by' => $actor?->getKey(), 'closed_at' => now(), 'closed_from_status' => $before->value,
                    'warehouse_was_enabled' => $warehouse, 'updated_at' => now()]));
            $rows = $closed === 1 ? Tenant::query()->whereKey($tenant->getKey())->where('status', $before->value)
                ->update(['status' => TenantStatus::Closed->value, 'updated_at' => now()]) : 0;
            if ($rows !== 1) {
                throw new LifecycleRefused('This offboarding or tenant changed meanwhile; nothing was closed.');
            }
            $this->sessions->revokeTenant($tenant);

            return $before;
        }));
        if ($before === null) {
            throw new LifecycleRefused('Only a tenant in use or suspended can be closed.');
        }
        // The warehouse feed stops (a closed tenant is skipped anyway; the setting makes it explicit and survives a reopen).
        if ($warehouse) {
            $this->tenants->runAs($tenant, fn () => $settings->set('warehouse.export_enabled', false, 'Tenant closed (offboarding)'));
        }
        $case = $this->fresh($case);
        $tenant->refresh();
        $this->audit->record(AuditAction::TenantClosed, $tenant, $case, $reason, $actor, ['offboarding' => $case->reference, 'warehouse_feed_stopped' => $warehouse],
            [['field' => 'status', 'before' => $before->value, 'after' => TenantStatus::Closed->value]]);

        return $case;
    }

    /** A closed tenant comes back, until a deletion has been approved or started. */
    public function reopen(TenantOffboarding $case, ?User $actor, string $reason): TenantOffboarding
    {
        $actor = PlatformOperator::assert($actor, 'reopen a tenant');
        $reason = PlatformOperator::reason($reason);
        $tenant = $this->tenantOf($case);

        $to = DB::transaction(function () use ($case, $tenant, $actor, $reason) {
            $locked = LegalHolds::lockFor($tenant->getKey()); // the same lock a deletion takes to start
            if ($locked === null || $locked->getKey() !== $case->getKey() || $locked->status !== OffboardingStatus::Closed) {
                throw new LifecycleRefused('Only a closed tenant can be reopened.');
            }
            if ($locked->deletion_started_at !== null) {
                throw new LifecycleRefused('A deletion of this tenant has started: it can no longer be reopened.');
            }
            $pending = $this->tenants->bypass(fn () => TenantDeletion::query()->where('tenant_id', $tenant->getKey())
                ->whereIn('status', [DeletionStatus::Planned->value, DeletionStatus::Approved->value, DeletionStatus::InProgress->value, DeletionStatus::Failed->value])->exists());
            if ($pending) {
                throw new LifecycleRefused('A deletion is planned or approved for this tenant: cancel or reject it before reopening.');
            }
            $to = TenantStatus::tryFrom((string) $locked->closed_from_status) ?? TenantStatus::Active;
            $rows = Tenant::query()->whereKey($tenant->getKey())->where('status', TenantStatus::Closed->value)->update(['status' => $to->value, 'updated_at' => now()]);
            if ($rows !== 1) {
                throw new LifecycleRefused('This tenant is not closed.');
            }
            $this->tenants->bypass(fn () => TenantOffboarding::query()->whereKey($case->getKey())->update(['status' => OffboardingStatus::Cancelled->value, 'open_key' => null,
                'cancelled_by' => $actor?->getKey(), 'cancelled_at' => now(), 'cancel_reason' => 'Reopened: '.$reason, 'updated_at' => now()]));

            return $to;
        });
        $case = $this->fresh($case);
        $tenant->refresh();
        if ($case->warehouse_was_enabled) {
            $this->tenants->runAs($tenant, fn () => app(SettingsRepository::class)->set('warehouse.export_enabled', true, 'Tenant reopened (offboarding cancelled)'));
        }
        $released = $tenant->isAccessible() ? app(DeferredTenantJobs::class)->release($tenant) : 0;
        $this->audit->record(AuditAction::TenantReopened, $tenant, $case, $reason, $actor, ['offboarding' => $case->reference, 'deferred_jobs_released' => $released],
            [['field' => 'status', 'before' => TenantStatus::Closed->value, 'after' => $to->value]]);

        return $case;
    }

    /** The customer declined the export, or received their data another way: recorded, so a deletion does not wait for a download. */
    public function waiveExport(TenantOffboarding $case, ?User $actor, string $reason): TenantOffboarding
    {
        $actor = PlatformOperator::assert($actor, 'waive a tenant\'s export');
        $reason = PlatformOperator::reason($reason);
        $changed = $this->tenants->bypass(fn () => TenantOffboarding::query()->whereKey($case->getKey())->whereNotNull('open_key')->whereNull('export_waived_at')
            ->update(['export_waived_by' => $actor?->getKey(), 'export_waived_at' => now(), 'export_waiver_reason' => $reason, 'updated_at' => now()]));
        if ($changed !== 1) {
            throw new LifecycleRefused('The export is already waived, or this offboarding is not open.');
        }
        $case = $this->fresh($case);
        $this->audit->record(AuditAction::ExportWaived, $this->tenantOf($case), $case, $reason, $actor, ['offboarding' => $case->reference]);

        return $case;
    }

    public function tenantOf(TenantOffboarding $case): Tenant
    {
        return Tenant::query()->findOrFail($case->tenant_id);
    }

    private function fresh(TenantOffboarding $case): TenantOffboarding
    {
        return $this->tenants->bypass(fn () => TenantOffboarding::query()->findOrFail($case->getKey()));
    }
}
