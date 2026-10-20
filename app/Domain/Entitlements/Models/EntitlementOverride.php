<?php

namespace App\Domain\Entitlements\Models;

use App\Domain\Entitlements\Enums\Capability;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * SaaS.3: an explicit platform exception for one capability ("AI on for the pilot until 31 March", "1,200 employees
 * this quarter"). It wins over the configuration while active, needs a reason, is revocable and audited.
 * Written only through EntitlementConfiguration and audited explicitly (automatic auditing is off for this model).
 */
#[Fillable(['tenant_id', 'capability', 'value_bool', 'value_int', 'effective_from', 'effective_to', 'status', 'reason', 'reference',
    'created_by', 'closed_by', 'closed_at', 'close_reason', 'superseded_by'])]
class EntitlementOverride extends Model
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
