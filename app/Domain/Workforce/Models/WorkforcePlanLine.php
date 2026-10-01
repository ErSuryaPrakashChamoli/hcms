<?php

namespace App\Domain\Workforce\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\JobFamily;
use App\Domain\Organisation\Models\Location;
use App\Domain\Organisation\Models\OrganisationNode;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 10: one intended movement in a plan version (new position, expansion, reduction, closure,
 * transfer, retirement, known exit, or baseline capacity) with headcount, FTE, optional planned cost
 * and its cost basis, and an effective date. A planning record — never an employee or a position.
 * Editable only while the plan version is a draft.
 */
#[Fillable(['tenant_id', 'workforce_plan_version_id', 'movement_type', 'position_id', 'organisation_node_id', 'location_id', 'job_family_id', 'designation_id', 'grade_id', 'employment_type_id', 'cost_centre_id', 'headcount', 'fte', 'planned_cost', 'cost_basis', 'effective_date', 'notes', 'created_position_id'])]
class WorkforcePlanLine extends Model
{
    use Auditable, BelongsToTenant;

    protected static function booted(): void
    {
        static::saving(function (self $l) {
            if (! array_key_exists($l->movement_type, config('peopleos.workforce.movement_types'))) {
                throw new \RuntimeException("Unknown plan movement '{$l->movement_type}'.");
            }
            if ($l->cost_basis !== null && ! array_key_exists($l->cost_basis, config('peopleos.workforce.cost_bases'))) {
                throw new \RuntimeException("Unknown cost basis '{$l->cost_basis}'.");
            }
            if ($l->planned_cost !== null && $l->cost_basis === null) {
                throw new \RuntimeException('A planned cost needs its cost basis (annualised salary, monthly salary, employer cost or position cost).');
            }
            if ((int) $l->headcount < 0 || (float) $l->fte < 0 || ((int) $l->headcount === 0 && (float) $l->fte === 0.0)) {
                throw new \RuntimeException('A plan line needs a headcount or FTE above zero.');
            }
            $version = WorkforcePlanVersion::query()->find($l->workforce_plan_version_id);
            $onlyLink = $l->exists && array_diff(array_keys($l->getDirty()), ['created_position_id', 'updated_at']) === [];
            if ($version !== null && ! $version->isEditable() && ! $onlyLink) {
                throw new \RuntimeException('Plan lines change only while the plan version is a draft.');
            }
        });
        static::deleting(function (self $l) {
            if (! WorkforcePlanVersion::query()->find($l->workforce_plan_version_id)?->isEditable()) {
                throw new \RuntimeException('Plan lines change only while the plan version is a draft.');
            }
        });
    }

    protected function casts(): array
    {
        return ['effective_date' => 'date', 'fte' => 'decimal:2', 'planned_cost' => 'decimal:2', 'headcount' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'workforce';
    }

    /** Planned costs are masked in the audit trail (field security). */
    public function auditSensitiveAttributes(): array
    {
        return ['planned_cost'];
    }

    /** +1 adds capacity, -1 removes it, per configuration. */
    public function sign(): int
    {
        return (int) (config("peopleos.workforce.movement_types.{$this->movement_type}.sign") ?? 0);
    }

    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(WorkforcePlanVersion::class, 'workforce_plan_version_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function createdPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'created_position_id');
    }

    public function organisationNode(): BelongsTo
    {
        return $this->belongsTo(OrganisationNode::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function jobFamily(): BelongsTo
    {
        return $this->belongsTo(JobFamily::class);
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }
}
