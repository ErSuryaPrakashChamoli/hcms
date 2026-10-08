<?php

namespace App\Domain\Platform\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\SessionSecurity;
use App\Domain\Platform\Enums\TenantStatus;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Support\PlatformOperator;
use App\Support\Tenancy\Jobs\DeferredTenantJobs;
use Illuminate\Support\Facades\DB;

/**
 * SaaS.2: the one way a tenant is suspended or reactivated.
 *
 * A suspended tenant means: no new sign-in (password, remember-me or SSO), every existing session ends at
 * its next request, API keys stop authenticating, protected downloads are refused, password reset and
 * invitation links do nothing, and scheduled tenant work is skipped (retention purge excepted).
 * Platform operators keep controlled, audited access (PlatformTenantAccess).
 *
 * The status changes through a conditional update (never a lock on the tenants row, which every child
 * insert holds a shared lock on), so two operators suspending at once produce one transition and one
 * audit trail; the second call reports that nothing changed.
 *
 * SaaS.8:
 * - Only a platform operator may act, checked here (PlatformOperator), not only by the page.
 * - Suspension records the status it interrupted (trial or active); reactivation restores it, instead of turning a
 *   suspended trial into an active tenant. A tenant suspended before SaaS.8 has none recorded and reactivates as active.
 * - Queued work that reached a worker during the suspension was deferred (DeferredTenantJobs); reactivation dispatches
 *   it again and records how much on both chains.
 */
final class TenantSuspensions
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly SessionSecurity $sessions,
        private readonly DeferredTenantJobs $deferred,
    ) {}

    public function suspend(Tenant $tenant, string $reason, ?User $actor = null): bool
    {
        $actor = PlatformOperator::assert($actor, 'suspend a tenant');
        $reason = PlatformOperator::reason($reason);

        $changed = DB::transaction(function () use ($tenant, &$before) {
            $before = Tenant::query()->whereKey($tenant->getKey())->value('status');
            $before = $before instanceof TenantStatus ? $before : TenantStatus::tryFrom((string) $before);
            if ($before === null || $before === TenantStatus::Suspended) {
                return false;
            }
            $rows = Tenant::query()->whereKey($tenant->getKey())->where('status', $before->value)
                ->update(['status' => TenantStatus::Suspended->value, 'suspended_from' => $before->value, 'suspended_at' => now(), 'updated_at' => now()]);
            if ($rows === 1) {
                $this->sessions->revokeTenant($tenant);
            }

            return $rows === 1;
        });
        if (! $changed) {
            return false;
        }

        $this->record(AuditAction::TenantSuspended, $tenant->refresh(), $before, TenantStatus::Suspended, $reason, $actor, ['sessions_ended' => true]);

        return true;
    }

    public function reactivate(Tenant $tenant, string $reason, ?User $actor = null): bool
    {
        $actor = PlatformOperator::assert($actor, 'reactivate a tenant');
        $reason = PlatformOperator::reason($reason);

        $changed = DB::transaction(function () use ($tenant, &$to) {
            $row = Tenant::query()->whereKey($tenant->getKey())->first(['id', 'status', 'suspended_from']);
            if ($row === null || $row->status !== TenantStatus::Suspended) {
                return false;
            }
            $to = TenantStatus::tryFrom((string) $row->suspended_from);
            $to = $to === null || $to === TenantStatus::Suspended ? TenantStatus::Active : $to;

            return Tenant::query()->whereKey($tenant->getKey())->where('status', TenantStatus::Suspended->value)
                ->update(['status' => $to->value, 'suspended_from' => null, 'suspended_at' => null, 'updated_at' => now()]) === 1;
        });
        if (! $changed) {
            return false;
        }

        $tenant->refresh();
        $pending = $this->deferred->pending($tenant);
        $this->record(AuditAction::TenantReactivated, $tenant, TenantStatus::Suspended, $to, $reason, $actor, ['deferred_jobs' => $pending]);
        $this->deferred->release($tenant);

        return true;
    }

    /** @param  array<string, mixed>  $extra */
    private function record(AuditAction $action, Tenant $tenant, TenantStatus $before, TenantStatus $after, string $reason, ?User $actor, array $extra): void
    {
        $changes = [['field' => 'status', 'before' => $before->value, 'after' => $after->value]];
        $metadata = ['subject_tenant_id' => $tenant->getKey(), 'subject_tenant' => $tenant->slug, 'sessions_ended' => $after === TenantStatus::Suspended] + $extra;

        $this->audit->record($action, 'platform', $tenant, $changes, $reason, tenantId: $tenant->getKey(), actor: $actor, metadata: $metadata);
        $this->audit->record($action, 'platform', null, $changes, $reason, entityLabel: "Tenant {$tenant->name}", actor: $actor, metadata: $metadata, platform: true);
    }
}
