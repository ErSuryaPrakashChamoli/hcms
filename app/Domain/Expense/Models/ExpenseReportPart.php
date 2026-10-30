<?php

namespace App\Domain\Expense\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HROne setup parity EX-C: the receipts of one workflow group inside a submitted expense report (expense.workflow.split_by:
 * one part for the whole report, or one per category or expense group; exceptional expenses always form their own part).
 * Each part is decided on its own — by a workflow listening to expense.report_submitted / expense.exceptional_submitted
 * (its context carries the workflow group), else in the Approval Center — and the report is decided when every part is.
 */
#[Fillable(['tenant_id', 'expense_report_id', 'employee_id', 'workflow_group', 'kind', 'label', 'status', 'total_claimed', 'total_approved', 'decided_at', 'decided_by', 'decision_note'])]
class ExpenseReportPart extends Model
{
    use Auditable, BelongsToTenant, ScopedByEmployee;

    public const STATUSES = ['pending' => 'Waiting for approval', 'approved' => 'Approved', 'partly_approved' => 'Partly approved', 'rejected' => 'Rejected', 'returned' => 'Returned', 'cancelled' => 'Cancelled'];

    protected $attributes = ['status' => 'pending', 'kind' => 'standard', 'total_claimed' => 0];

    protected function casts(): array
    {
        return ['total_claimed' => 'decimal:2', 'total_approved' => 'decimal:2', 'decided_at' => 'datetime'];
    }

    public function auditLabel(): string
    {
        return 'Expense report part #'.($this->getAttributes()['id'] ?? '').' ('.($this->getAttributes()['label'] ?? '').')';
    }

    public function isExceptional(): bool
    {
        return $this->kind === 'exceptional';
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(ExpenseReport::class, 'expense_report_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(ExpenseReceipt::class, 'expense_report_part_id')->orderBy('expense_date')->orderBy('id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
