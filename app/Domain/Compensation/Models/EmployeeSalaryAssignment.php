<?php

namespace App\Domain\Compensation\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Compensation\Exceptions\CompensationRuleViolation;
use App\Domain\Compensation\Services\AssignmentWriter;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\EffectiveDating\HasEffectiveDates;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of an employee's canonical compensation history (§70, §100; Phase 11 ADR: Compensation is
 * the domain owner and sole write authority of employee_salary_assignments). Sensitive: CTC, variable
 * target, component amounts and the reason are masked in audit.
 *
 * Rows are written only by AssignmentWriter while it executes an approved compensation change. Once
 * written, the compensation itself (structure, amounts, currency, start date) never changes: only the
 * end date (the timeline closes or reopens around a later row) and the status (a same-date correction
 * supersedes a row; cancelling a scheduled change cancels it) move, and no row is ever deleted. Only
 * active rows form the timeline Payroll consumes through the CompensationOutput contract.
 */
#[Fillable(['tenant_id', 'employee_id', 'compensation_change_id', 'salary_structure_id', 'ctc_annual', 'currency', 'pay_frequency', 'variable_target_annual', 'component_values', 'change_type', 'status', 'active_key', 'superseded_by_id', 'effective_from', 'effective_to', 'reason', 'created_by', 'approved_by', 'approved_at'])]
class EmployeeSalaryAssignment extends Model
{
    use Auditable, BelongsToTenant, HasEffectiveDates;
    use ScopedByEmployee;

    /** Legacy change types (Phase 4 rows); new rows carry the compensation change's type. */
    public const CHANGE_TYPES = ['hire' => 'Hire', 'revision' => 'Revision', 'promotion' => 'Promotion', 'correction' => 'Correction', 'transfer' => 'Transfer'];

    public const STATUSES = ['active' => 'Active', 'superseded' => 'Superseded (corrected)', 'cancelled' => 'Cancelled before it took effect'];

    /** Columns that may move after the row is written (the timeline and the row's status). */
    public const MUTABLE = ['effective_to', 'status', 'active_key', 'superseded_by_id', 'updated_at'];

    protected $attributes = ['status' => 'active', 'active_key' => 1, 'pay_frequency' => 'monthly'];

    protected static function booted(): void
    {
        static::creating(function (self $row): void {
            if (! AssignmentWriter::isWriting()) {
                throw new CompensationRuleViolation('Employee compensation is written only by executing an approved compensation change.');
            }
        });
        static::updating(function (self $row): void {
            if (! AssignmentWriter::isWriting()) {
                throw new CompensationRuleViolation('Employee compensation is changed only by executing an approved compensation change.');
            }
            $locked = array_diff(array_keys($row->getDirty()), self::MUTABLE);
            if ($locked !== []) {
                throw new CompensationRuleViolation('Approved compensation is immutable ('.implode(', ', $locked).'); a correction is a new approved change.');
            }
        });
        static::deleting(function (): void {
            throw new CompensationRuleViolation('Compensation history is never deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'ctc_annual' => 'decimal:2',
            'variable_target_annual' => 'decimal:2',
            'component_values' => 'array',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function auditModule(): string
    {
        return 'compensation';
    }

    public function auditLabel(): string
    {
        return 'Compensation from '.$this->effective_from?->toDateString();
    }

    public function auditSensitiveAttributes(): array
    {
        // Phase 11 §21: compensation reasons are sensitive too.
        return ['ctc_annual', 'variable_target_annual', 'component_values', 'reason'];
    }

    /** Audit rows written before Phase 11 name the class's previous (Payroll) location. */
    public function auditEntityAliases(): array
    {
        return ['App\\Domain\\Payroll\\Models\\EmployeeSalaryAssignment'];
    }

    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), 'active');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class, 'salary_structure_id');
    }

    public function change(): BelongsTo
    {
        return $this->belongsTo(CompensationChange::class, 'compensation_change_id');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function monthlyCtc(): float
    {
        return round((float) $this->ctc_annual / 12, 2);
    }

    /** Monthly fixed value for a component code (component_values are stored monthly). */
    public function valueFor(string $code): float
    {
        return (float) data_get($this->component_values, strtoupper($code), 0);
    }
}
