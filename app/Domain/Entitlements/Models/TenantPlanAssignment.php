<?php

namespace App\Domain\Entitlements\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * SaaS.4: the published plan version a tenant is on, for an effective-dated period (business dates, inclusive).
 * The version and the start date never change; a later assignment ends this one (effective_to) or, if it has not
 * started, cancels it. Part of the tenant's entitlement configuration (BelongsToTenant, ADR-0027). Written only
 * through EntitlementConfiguration and audited explicitly on the tenant chain and the platform chain.
 */
#[Fillable(['tenant_id', 'plan_version_id', 'effective_from', 'effective_to', 'status', 'reason', 'reference',
    'created_by', 'closed_by', 'closed_at', 'close_reason', 'superseded_by'])]
class TenantPlanAssignment extends Model
{
    use BelongsToTenant;

    public const ACTIVE = 'active';

    public const CANCELLED = 'cancelled';

    protected static function booted(): void
    {
        static::updating(function (self $assignment): void {
            if ($assignment->isDirty(['tenant_id', 'plan_version_id', 'effective_from'])) {
                throw new RuntimeException('A plan assignment keeps its plan version and start date: assign again instead.');
            }
        });
    }

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date', 'closed_at' => 'datetime'];
    }

    /** @return BelongsTo<PlanVersion, $this> */
    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(PlanVersion::class);
    }

    /** @return array<string, mixed> the state's row shape */
    public function toStateRow(): array
    {
        return ['id' => $this->id, 'plan_version_id' => $this->plan_version_id, 'from' => $this->effective_from->toDateString(), 'to' => $this->effective_to?->toDateString()];
    }
}
