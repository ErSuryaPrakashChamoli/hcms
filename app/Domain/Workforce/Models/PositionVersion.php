<?php

namespace App\Domain\Workforce\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Career\Models\CareerTrack;
use App\Domain\Organisation\Models\CostCentre;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\EmploymentType;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\JobFamily;
use App\Domain\Organisation\Models\Location;
use App\Domain\Organisation\Models\OrganisationNode;
use App\Domain\Workforce\Concerns\ScopedByOrganisationDimensions;
use App\Support\EffectiveDating\HasEffectiveDates;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 10: one effective-dated definition of a position (title, role, organisation, location,
 * capacity, FTE, status, parent). A draft version is edited in place; every later change is a new
 * version from an effective date and the previous one is closed the day before (a same-day change
 * leaves a zero-length version: kept as history, never in force). Never deleted; only effective_to
 * is ever set on a non-draft version, once.
 */
#[Fillable(['tenant_id', 'position_id', 'version', 'status', 'effective_from', 'effective_to', 'title', 'designation_id', 'job_family_id', 'career_track_id', 'company_id', 'organisation_node_id', 'business_unit_id', 'division_id', 'department_id', 'team_id', 'location_id', 'establishment_id', 'legal_entity_id', 'employment_type_id', 'worker_type', 'grade_id', 'cost_centre_id', 'parent_position_id', 'occupancy_mode', 'headcount', 'fte', 'fte_capacity', 'standard_hours', 'change_type', 'reason', 'checksum', 'created_by'])]
class PositionVersion extends Model
{
    use Auditable, BelongsToTenant, HasEffectiveDates, ScopedByOrganisationDimensions;

    public const UPDATED_AT = null;

    /** Mirror the column defaults so guards see them before the insert. */
    protected $attributes = ['worker_type' => 'employee', 'occupancy_mode' => 'single', 'headcount' => 1, 'fte' => 1, 'fte_capacity' => 1];

    /** The definition fields covered by the checksum. */
    public const DEFINITION = ['status', 'effective_from', 'title', 'designation_id', 'job_family_id', 'career_track_id', 'company_id', 'organisation_node_id', 'location_id', 'establishment_id', 'legal_entity_id', 'employment_type_id', 'worker_type', 'grade_id', 'cost_centre_id', 'parent_position_id', 'occupancy_mode', 'headcount', 'fte', 'fte_capacity', 'standard_hours'];

    protected static function booted(): void
    {
        static::saving(function (self $v) {
            if (! array_key_exists($v->status, config('peopleos.workforce.position_statuses'))) {
                throw new \RuntimeException("Unknown position status '{$v->status}'.");
            }
            if (! array_key_exists($v->occupancy_mode, config('peopleos.workforce.occupancy_modes'))) {
                throw new \RuntimeException("Unknown occupancy mode '{$v->occupancy_mode}'.");
            }
            if (! array_key_exists($v->worker_type, config('peopleos.workforce.worker_types'))) {
                throw new \RuntimeException("Unknown worker type '{$v->worker_type}'.");
            }
            if ((int) $v->headcount < 1 || (float) $v->fte <= 0 || (float) $v->fte_capacity <= 0) {
                throw new \RuntimeException('A position needs at least one seat and an FTE above zero.');
            }
            if ($v->occupancy_mode === 'single' && (int) $v->headcount !== 1) {
                throw new \RuntimeException('A single-occupancy position has exactly one seat.');
            }
            if ((float) $v->fte_capacity > (int) $v->headcount * (float) $v->fte + 0.0001) {
                throw new \RuntimeException('FTE capacity cannot exceed seats × FTE per seat.');
            }
            if ($v->parent_position_id !== null && (int) $v->parent_position_id === (int) $v->position_id) {
                throw new \RuntimeException('A position cannot be its own parent.');
            }
            $v->checksum = hash('sha256', (string) json_encode(collect(self::DEFINITION)->mapWithKeys(fn ($f) => [$f => (string) $v->getAttribute($f) === '' ? null : (string) $v->getRawOriginalOrAttribute($f)])->all()));
        });
        static::updating(function (self $v) {
            if ($v->getRawOriginal('status') === 'draft') {
                return; // a draft is not yet history
            }
            $dirty = array_keys($v->getDirty());
            if (array_diff($dirty, ['effective_to', 'checksum']) !== [] || $v->getRawOriginal('effective_to') !== null) {
                throw new \RuntimeException('A position version is immutable; record a new version from an effective date.');
            }
        });
        static::deleting(fn () => throw new \RuntimeException('Position versions are never deleted.'));
    }

    /** Raw stored value for checksum stability (dates and decimals as stored). */
    public function getRawOriginalOrAttribute(string $field): mixed
    {
        return $this->getAttributes()[$field] ?? null;
    }

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date', 'headcount' => 'integer', 'fte' => 'decimal:2', 'fte_capacity' => 'decimal:2', 'standard_hours' => 'decimal:2', 'version' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'workforce';
    }

    public function auditLabel(): string
    {
        return "Position version {$this->version}";
    }

    /** In force on at least one day (same-day superseded versions are not). */
    public function isInForce(): bool
    {
        return $this->effective_to === null || $this->effective_to->gte($this->effective_from);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'parent_position_id');
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    public function jobFamily(): BelongsTo
    {
        return $this->belongsTo(JobFamily::class);
    }

    public function careerTrack(): BelongsTo
    {
        return $this->belongsTo(CareerTrack::class);
    }

    public function organisationNode(): BelongsTo
    {
        return $this->belongsTo(OrganisationNode::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function employmentType(): BelongsTo
    {
        return $this->belongsTo(EmploymentType::class);
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class);
    }
}
