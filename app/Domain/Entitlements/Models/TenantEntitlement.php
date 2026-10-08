<?php

namespace App\Domain\Entitlements\Models;

use App\Domain\Entitlements\Enums\Capability;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * SaaS.3: one effective-dated value of the tenant's commercial configuration for one capability. Value and start date
 * never change; a later change ends this row (effective_to) or, if it has not started, cancels it.
 * Written only through EntitlementConfiguration and audited explicitly (automatic auditing is off for this model).
 */
#[Fillable(['tenant_id', 'capability', 'value_bool', 'value_int', 'effective_from', 'effective_to', 'status', 'reason', 'reference',
    'created_by', 'closed_by', 'closed_at', 'close_reason', 'superseded_by'])]
class TenantEntitlement extends Model
{
    use BelongsToTenant;

    public const ACTIVE = 'active';

    public const CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return ['capability' => Capability::class, 'value_bool' => 'boolean', 'value_int' => 'integer', 'effective_from' => 'date', 'effective_to' => 'date', 'closed_at' => 'datetime'];
    }

    /** @return array<string, mixed> the evaluator's row shape */
    public function toStateRow(): array
    {
        return ['id' => $this->id, 'capability' => $this->capability->value, 'value_bool' => $this->value_bool, 'value_int' => $this->value_int,
            'from' => $this->effective_from->toDateString(), 'to' => $this->effective_to?->toDateString()];
    }
}
