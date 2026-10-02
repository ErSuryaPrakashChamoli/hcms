<?php

namespace App\Domain\Compensation\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Compensation\Exceptions\CompensationRuleViolation;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Workforce\Models\Position;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Phase 11: a proposed change to one employee's compensation. Draft → submitted → under review →
 * approved → scheduled → effective (or rejected / cancelled), driven only by CompensationChanges with
 * four different people: proposer, reviewer, approver and executor. Executing (scheduling) an approved
 * change is the only way a row reaches the canonical history (employee_salary_assignments).
 *
 * The proposed compensation is content: it is editable only while the change is a draft. Reasons and
 * internal notes are confidential: internal notes are encrypted and never shown to the employee.
 */
#[Fillable(['tenant_id', 'reference', 'employee_id', 'change_type', 'source', 'status', 'effective_from', 'salary_structure_id', 'ctc_annual', 'currency', 'pay_frequency', 'component_values', 'variable_target_annual', 'previous_assignment_id', 'previous_ctc_annual', 'previous_currency', 'previous_salary_structure_id', 'previous_component_values', 'from_grade_id', 'to_grade_id', 'from_position_id', 'to_position_id', 'reason', 'internal_notes', 'proposed_by', 'submitted_at', 'reviewed_by', 'reviewed_at', 'review_note', 'approved_by', 'approved_at', 'rejected_by', 'rejected_at', 'decision_note', 'scheduled_by', 'scheduled_at', 'effected_by', 'effective_at', 'cancelled_by', 'cancelled_at', 'cancel_reason', 'employee_salary_assignment_id', 'lock_version'])]
class CompensationChange extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const STATUSES = ['draft' => 'Draft', 'submitted' => 'Submitted', 'under_review' => 'Under review', 'approved' => 'Approved', 'scheduled' => 'Scheduled', 'effective' => 'Effective', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];

    /** The proposal itself; frozen from submission (a returned change goes back to draft). */
    public const CONTENT = ['employee_id', 'change_type', 'effective_from', 'salary_structure_id', 'ctc_annual', 'currency', 'pay_frequency', 'component_values', 'variable_target_annual', 'from_grade_id', 'to_grade_id', 'from_position_id', 'to_position_id', 'reason'];

    /** Statuses whose compensation is approved (the canonical row exists from scheduling). */
    public const APPROVED = ['approved', 'scheduled', 'effective'];

    /** Statuses still waiting for a decision. */
    public const PENDING = ['submitted', 'under_review'];

    protected $attributes = ['status' => 'draft', 'source' => 'manual', 'pay_frequency' => 'monthly', 'lock_version' => 0];

    protected static function booted(): void
    {
        static::creating(function (self $change): void {
            $change->reference ??= (string) Str::ulid();
        });
        static::updating(function (self $change): void {
            $locked = array_intersect(array_keys($change->getDirty()), self::CONTENT);
            if ($locked !== [] && $change->getRawOriginal('status') !== 'draft') {
                throw new CompensationRuleViolation('A compensation change can be edited only while it is a draft ('.implode(', ', $locked).').');
            }
        });
        static::deleting(function (self $change): void {
            if ($change->getRawOriginal('status') !== 'draft') {
                throw new CompensationRuleViolation('Only a draft compensation change can be deleted; cancel it instead.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'ctc_annual' => 'decimal:2',
            'variable_target_annual' => 'decimal:2',
            'previous_ctc_annual' => 'decimal:2',
            'component_values' => 'array',
            'previous_component_values' => 'array',
            'internal_notes' => 'encrypted',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'effective_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    public function auditModule(): string
    {
        return 'compensation';
    }

    public function auditLabel(): string
    {
        return 'Compensation change '.$this->reference;
    }

    public function auditSensitiveAttributes(): array
    {
        return ['ctc_annual', 'variable_target_annual', 'component_values', 'previous_ctc_annual', 'previous_component_values', 'internal_notes', 'review_note', 'decision_note', 'reason'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class, 'salary_structure_id');
    }

    public function previousStructure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class, 'previous_salary_structure_id');
    }

    public function previousAssignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeSalaryAssignment::class, 'previous_assignment_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeSalaryAssignment::class, 'employee_salary_assignment_id');
    }

    public function fromGrade(): BelongsTo
    {
        return $this->belongsTo(Grade::class, 'from_grade_id');
    }

    public function toGrade(): BelongsTo
    {
        return $this->belongsTo(Grade::class, 'to_grade_id');
    }

    public function fromPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'from_position_id');
    }

    public function toPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'to_position_id');
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scheduled_by');
    }

    public function monthlyCtc(): float
    {
        return round((float) $this->ctc_annual / 12, 2);
    }

    /** Annual CTC increase over the compensation in force before the change (0 when there was none). */
    public function annualIncrease(): float
    {
        return $this->previous_ctc_annual === null ? 0.0 : round((float) $this->ctc_annual - (float) $this->previous_ctc_annual, 2);
    }

    /** The users who already acted on this change, by duty (used for separation of duties). */
    public function actors(): array
    {
        return array_filter(['proposer' => $this->proposed_by, 'reviewer' => $this->reviewed_by, 'approver' => $this->approved_by, 'executor' => $this->scheduled_by]);
    }

    public function typeLabel(): string
    {
        return config("peopleos.compensation.change_types.{$this->change_type}", ucfirst(str_replace('_', ' ', (string) $this->change_type)));
    }
}
