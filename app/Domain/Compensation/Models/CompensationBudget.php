<?php

namespace App\Domain\Compensation\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Compensation\Exceptions\CompensationRuleViolation;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Company;
use App\Domain\Workforce\Concerns\ScopedByOrganisationDimensions;
use App\Domain\Workforce\Models\WorkforceBudget;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 11: a compensation budget — an amount of annualised CTC increase for a company (and optionally
 * an organisation unit / location) and period, in one currency. Measures in CompensationBudgets.
 * Approved budgets change only by being closed. Amounts are financial (masked in audit).
 */
#[Fillable(['tenant_id', 'code', 'name', 'company_id', 'organisation_node_id', 'business_unit_id', 'division_id', 'department_id', 'team_id', 'location_id', 'period_start', 'period_end', 'currency', 'basis', 'amount', 'charged_amount', 'status', 'workforce_budget_id', 'notes', 'prepared_by', 'approved_by', 'approved_at', 'lock_version'])]
class CompensationBudget extends Model
{
    use Auditable, BelongsToTenant, ScopedByOrganisationDimensions;

    public const STATUSES = ['draft' => 'Draft', 'approved' => 'Approved', 'closed' => 'Closed'];

    protected $attributes = ['status' => 'draft', 'basis' => 'annual_ctc_increase', 'charged_amount' => 0, 'lock_version' => 0];

    protected static function booted(): void
    {
        static::updating(function (self $budget): void {
            $locked = array_intersect(array_keys($budget->getDirty()), ['company_id', 'organisation_node_id', 'period_start', 'period_end', 'currency', 'basis', 'amount']);
            if ($locked !== [] && $budget->getRawOriginal('status') !== 'draft') {
                throw new CompensationRuleViolation('An approved budget does not change; close it and prepare a new one.');
            }
        });
    }

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'amount' => 'decimal:2', 'charged_amount' => 'decimal:2', 'approved_at' => 'datetime', 'lock_version' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'compensation';
    }

    public function auditLabel(): string
    {
        return "Compensation budget {$this->code}";
    }

    public function auditSensitiveAttributes(): array
    {
        return ['amount', 'charged_amount'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function workforceBudget(): BelongsTo
    {
        return $this->belongsTo(WorkforceBudget::class);
    }

    public function changes(): HasMany
    {
        return $this->hasMany(CompensationChange::class);
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
