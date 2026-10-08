<?php

namespace App\Domain\Platform\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Support\LifecycleRefused;
use App\Domain\Platform\Support\PlatformOperator;
use App\Support\Tenancy\Jobs\DeferredTenantJobs;

/**
 * SaaS.8: an operator dispatches again the work deferred while a tenant was suspended, when the automatic release on
 * reactivation did not finish (a worker or database failure mid-release). Operator and reason required; both chains.
 */
final class TenantDeferredWork
{
    public function __construct(private readonly DeferredTenantJobs $deferred, private readonly AuditRecorder $audit) {}

    public function release(Tenant $tenant, ?User $actor, ?string $reason): int
    {
        $actor = PlatformOperator::assert($actor, "release a tenant's deferred work");
        $reason = PlatformOperator::reason($reason);
        if (! $tenant->fresh()?->isAccessible()) {
            throw new LifecycleRefused('Deferred work is released only when the tenant is active again (reactivate it first).');
        }

        $released = $this->deferred->release($tenant);
        $metadata = ['subject_tenant_id' => $tenant->getKey(), 'subject_tenant' => $tenant->slug, 'released' => $released];
        $this->audit->record(AuditAction::DeferredWorkReleased, 'platform', $tenant, [], $reason, tenantId: $tenant->getKey(), actor: $actor, metadata: $metadata);
        $this->audit->record(AuditAction::DeferredWorkReleased, 'platform', null, [], $reason, entityLabel: "Tenant {$tenant->name}", actor: $actor, metadata: $metadata, platform: true);

        return $released;
    }
}
