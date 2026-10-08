<?php

namespace App\Domain\Platform\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\SessionSecurity;
use App\Domain\Platform\Enums\TenantStatus;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * SaaS.2: the one way a tenant is suspended or reactivated.
 *
 * A suspended tenant means: no new sign-in (password, remember-me or SSO), every existing session ends at
 * its next request, API keys stop authenticating, protected downloads are refused, password reset and
 * invitation links do nothing, and queued and scheduled tenant work is skipped (retention purge excepted).
 * Platform operators keep controlled, audited access (PlatformTenantAccess).
 *
 * The status changes through a conditional update (never a lock on the tenants row, which every child
 * insert holds a shared lock on), so two operators suspending at once produce one transition and one
 * audit trail; the second call reports that nothing changed.
 */
final class TenantSuspensions
{
    public function __construct(private readonly AuditRecorder $audit, private readonly SessionSecurity $sessions) {}

    public function suspend(Tenant $tenant, string $reason, ?User $actor = null): bool
    {
        return $this->move($tenant, TenantStatus::Suspended, $reason, $actor, AuditAction::TenantSuspended);
    }

    public function reactivate(Tenant $tenant, string $reason, ?User $actor = null): bool
    {
        return $this->move($tenant, TenantStatus::Active, $reason, $actor, AuditAction::TenantReactivated);
    }

    private function move(Tenant $tenant, TenantStatus $to, string $reason, ?User $actor, AuditAction $action): bool
    {
        if (Str::length(trim($reason)) < 5) {
            throw new RuntimeException('A reason is required.');
        }

        $before = Tenant::query()->whereKey($tenant->getKey())->value('status');
        $changed = DB::transaction(function () use ($tenant, $to) {
            $rows = Tenant::query()->whereKey($tenant->getKey())
                ->when($to === TenantStatus::Suspended, fn ($q) => $q->where('status', '!=', TenantStatus::Suspended->value), fn ($q) => $q->where('status', TenantStatus::Suspended->value))
                ->update(['status' => $to->value, 'updated_at' => now()]);
            if ($rows === 1 && $to === TenantStatus::Suspended) {
                $this->sessions->revokeTenant($tenant);
            }

            return $rows === 1;
        });
        if (! $changed) {
            return false;
        }

        $tenant->refresh();
        $changes = [['field' => 'status', 'before' => $before instanceof TenantStatus ? $before->value : (string) $before, 'after' => $to->value]];
        $metadata = ['subject_tenant_id' => $tenant->getKey(), 'subject_tenant' => $tenant->slug, 'sessions_ended' => $to === TenantStatus::Suspended];

        $this->audit->record($action, 'platform', $tenant, $changes, $reason, tenantId: $tenant->getKey(), actor: $actor, metadata: $metadata);
        $this->audit->record($action, 'platform', null, $changes, $reason, entityLabel: "Tenant {$tenant->name}", actor: $actor, metadata: $metadata, platform: true);

        return true;
    }
}
