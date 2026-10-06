<?php

namespace App\Domain\Entitlements\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * SaaS.3: aggregated shadow decisions: per tenant, day, capability, outcome, reason and surface, how often (a lower
 * bound) and when it was first and last seen. Observability, not commercial state and not audit: written by
 * ShadowRecorder off the request path, purged by retention.
 */
#[Fillable(['tenant_id', 'observed_on', 'capability', 'outcome', 'reason', 'surface', 'occurrences', 'first_seen_at', 'last_seen_at',
    'last_source', 'last_entitlement_id', 'last_override_id', 'last_assignment_id'])]
class EntitlementShadowObservation extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['observed_on' => 'date', 'occurrences' => 'integer', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }
}
