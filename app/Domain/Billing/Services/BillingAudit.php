<?php

namespace App\Domain\Billing\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * SaaS.7: how billing, tax and payment changes are audited. Platform catalogue changes go on Markedge's platform
 * chain; a tenant's financial records go on the tenant chain and, with the tenant named, on the platform chain.
 * Every event carries the actor (null for a provider or the scheduler, named in `trigger`), the reason, before
 * and after, the effective date and a correlation or idempotency key.
 */
final class BillingAudit
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @param  list<array{field: string, before: mixed, after: mixed}>  $changes  @param  array<string, mixed>  $metadata */
    public function platform(AuditAction $action, string $module, Model $entity, string $label, array $changes, string $reason, ?User $actor,
        array $metadata = [], ?string $effectiveDate = null): void
    {
        $this->audit->record($action, $module, $entity, $changes, $reason, effectiveDate: $effectiveDate ?? now()->toDateString(), entityLabel: $label,
            actor: $actor, metadata: $metadata + ['trigger' => $actor === null ? 'system' : 'operator'], platform: true);
    }

    /** @param  list<array{field: string, before: mixed, after: mixed}>  $changes  @param  array<string, mixed>  $metadata */
    public function both(AuditAction $action, string $module, Tenant $tenant, Model $entity, string $label, array $changes, string $reason, ?User $actor,
        array $metadata = [], ?string $effectiveDate = null): void
    {
        $metadata = $metadata + ['subject_tenant_id' => $tenant->id, 'subject_tenant' => $tenant->slug, 'trigger' => $metadata['trigger'] ?? ($actor === null ? 'system' : 'operator')];
        $effective = $effectiveDate ?? now()->toDateString();
        $this->audit->record($action, $module, $entity, $changes, $reason, effectiveDate: $effective, entityLabel: $label, tenantId: $tenant->id, actor: $actor, metadata: $metadata);
        $this->audit->record($action, $module, null, $changes, $reason, effectiveDate: $effective, entityLabel: "Tenant {$tenant->name} · {$label}", actor: $actor,
            metadata: $metadata + ['entity_id' => $entity->getKey(), 'entity_type' => class_basename($entity)], platform: true);
    }
}
