<?php

namespace App\Domain\Compensation\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Compensation\Exceptions\CompensationRuleViolation;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Company;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Workforce\Concerns\ScopedByOrganisationDimensions;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 11: a bulk compensation cycle (annual increment, promotion, market adjustment). Its lines are
 * CompensationChange rows (source "cycle"). Rules are editable only while the cycle is a draft.
 */
#[Fillable(['tenant_id', 'code', 'name', 'cycle_type', 'company_id', 'organisation_node_id', 'business_unit_id', 'division_id', 'department_id', 'team_id', 'location_id', 'effective_from', 'status', 'compensation_budget_id', 'performance_cycle_id', 'default_increase_percent', 'rating_increase_percent', 'employee_count', 'snapshot_checksum', 'operation_id', 'prepared_by', 'populated_at', 'submitted_at', 'reviewed_by', 'reviewed_at', 'approved_by', 'approved_at', 'executed_by', 'executed_at', 'closed_by', 'closed_at', 'decision_note', 'lock_version'])]
class CompensationCycle extends Model
{
    use Auditable, BelongsToTenant, ScopedByOrganisationDimensions;

    public const STATUSES = ['draft' => 'Draft', 'submitted' => 'Submitted', 'under_review' => 'Under review', 'approved' => 'Approved', 'executed' => 'Executed', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];

    public const RULES = ['cycle_type', 'company_id', 'organisation_node_id', 'location_id', 'effective_from', 'compensation_budget_id', 'performance_cycle_id', 'default_increase_percent', 'rating_increase_percent'];

    protected $attributes = ['status' => 'draft', 'default_increase_percent' => 0, 'employee_count' => 0, 'lock_version' => 0];

    protected static function booted(): void
    {
        static::updating(function (self $cycle): void {
            $locked = array_intersect(array_keys($cycle->getDirty()), self::RULES);
            if ($locked !== [] && $cycle->getRawOriginal('status') !== 'draft') {
                throw new CompensationRuleViolation('A compensation cycle\'s rules are fixed from submission ('.implode(', ', $locked).').');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'effective_from' => 'date', 'default_increase_percent' => 'decimal:2', 'rating_increase_percent' => 'array', 'employee_count' => 'integer',
            'populated_at' => 'datetime', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'approved_at' => 'datetime', 'executed_at' => 'datetime', 'closed_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    public function auditModule(): string
    {
        return 'compensation';
    }

    public function auditLabel(): string
    {
        return "Compensation cycle {$this->code}";
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CompensationChange::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(CompensationBudget::class, 'compensation_budget_id');
    }

    public function performanceCycle(): BelongsTo
    {
        return $this->belongsTo(PerformanceCycle::class);
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }
}
