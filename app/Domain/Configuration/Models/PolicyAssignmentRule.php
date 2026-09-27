<?php

namespace App\Domain\Configuration\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\EffectiveDating\HasEffectiveDates;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * IF <conditions> THEN policy (§26, §43). Conditions: [{field, operator, value}], combined with
 * `match` = all | any. Lower priority number wins.
 */
#[Fillable(['tenant_id', 'policy_type', 'policy_id', 'name', 'priority', 'match', 'conditions', 'status', 'effective_from', 'effective_to'])]
class PolicyAssignmentRule extends Model
{
    use Auditable, BelongsToTenant, HasEffectiveDates;

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'priority' => 'integer',
            'status' => ActiveStatus::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function auditModule(): string
    {
        return 'configuration';
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }
}
