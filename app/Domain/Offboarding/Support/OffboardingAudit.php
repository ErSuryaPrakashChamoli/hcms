<?php

namespace App\Domain\Offboarding\Support;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Offboarding\Models\TenantDeletionStep;
use App\Domain\Platform\Enums\TenantStatus;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * SaaS.9: every exit event is recorded on the platform chain (Markedge's accountability record, kept), and on the
 * tenant's own chain while that chain still exists (it is the last thing a deletion removes; after that, and for a
 * deleted tenant, the platform chain alone). Metadata names the tenant and never carries personal data.
 */
final class OffboardingAudit
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<int, array{field: string, before: mixed, after: mixed}>  $changes
     */
    public function record(AuditAction $action, Tenant $tenant, ?Model $entity, string $reason, ?User $actor, array $metadata = [], array $changes = [], bool $tenantChain = true): void
    {
        $metadata = ['subject_tenant_id' => $tenant->getKey(), 'subject_tenant' => $tenant->slug] + $metadata;
        if ($tenantChain && $tenant->status !== TenantStatus::Deleted && ! self::tenantChainDeleted($tenant)) {
            $this->audit->record($action, 'offboarding', $entity, $changes, $reason, tenantId: $tenant->getKey(), actor: $actor, metadata: $metadata);
        }
        $this->audit->record($action, 'offboarding', null, $changes, $reason, entityLabel: "Tenant {$tenant->name}", actor: $actor, metadata: $metadata, platform: true);
    }

    /** After a deletion removed the tenant's audit chain, its events go to the platform chain only (no new tenant chain). */
    public static function tenantChainDeleted(Tenant $tenant): bool
    {
        return app(TenantContext::class)->bypass(fn () => TenantDeletionStep::query()->where('tenant_id', $tenant->getKey())->where('kind', 'audit')
            ->where('status', TenantDeletionStep::DONE)->exists());
    }
}
