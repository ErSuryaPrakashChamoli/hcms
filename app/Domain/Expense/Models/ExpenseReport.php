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
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HROne setup parity EX-C: an expense report — receipts an employee sends for approval together. Submitted inside the
 * submission windows (expense.report_window.*), it is split into parts by workflow group, each decided line by line;
 * once every part is decided the report is approved, partly approved or rejected, open advances are settled against it
 * oldest first (EX-D) and what is left is its net payable. It is paid outside payroll (Pay expenses records the
 * reference), or scheduled for payroll (EX-E: on approval when there is no payment step, else from Pay expenses) and paid by
 * the run that carried it once that run is finalized, or paid in the full and final settlement at exit. Returned reports
 * go back to the employee to change.
 */
#[Fillable([
    'tenant_id', 'number', 'employee_id', 'title', 'period_from', 'period_to', 'status', 'currency',
    'total_claimed', 'total_approved', 'reimbursable_approved', 'advance_adjusted', 'net_payable', 'window_cycle', 'filed_on_behalf',
    'submitted_at', 'submitted_by', 'decided_at', 'decided_by', 'returned_at', 'return_note', 'cancelled_at', 'cancel_reason',
    'payment_channel', 'payment_scheduled_at', 'payment_scheduled_by', 'paid_on', 'payment_reference', 'paid_by', 'payroll_run_id', 'final_settlement_id', 'created_by',
])]
class ExpenseReport extends Model
{
    use Auditable, BelongsToTenant, ScopedByEmployee;

    public const STATUSES = [
        'draft' => 'Draft', 'submitted' => 'Submitted', 'returned' => 'Returned to employee', 'approved' => 'Approved',
        'partly_approved' => 'Partly approved', 'rejected' => 'Rejected', 'paid' => 'Paid', 'cancelled' => 'Cancelled',
    ];

    /** Statuses in which the employee may change the report. */
    public const EDITABLE = ['draft', 'returned'];

    /** Decided statuses that may still be paid. */
    public const DECIDED = ['approved', 'partly_approved', 'rejected'];

    /** How a report is (to be) paid: outside payroll (Pay expenses), with payroll, or in the final settlement (EX-E). */
    public const CHANNELS = ['bank' => 'Outside payroll', 'payroll' => 'With payroll', 'settlement' => 'In the full and final settlement'];

    protected $attributes = ['status' => 'draft', 'total_claimed' => 0, 'total_approved' => 0, 'reimbursable_approved' => 0, 'advance_adjusted' => 0, 'net_payable' => 0, 'filed_on_behalf' => false];

    protected function casts(): array
    {
        return [
            'period_from' => 'date', 'period_to' => 'date', 'paid_on' => 'date', 'payment_scheduled_at' => 'datetime',
            'submitted_at' => 'datetime', 'decided_at' => 'datetime', 'returned_at' => 'datetime', 'cancelled_at' => 'datetime',
            'total_claimed' => 'decimal:2', 'total_approved' => 'decimal:2', 'reimbursable_approved' => 'decimal:2', 'advance_adjusted' => 'decimal:2', 'net_payable' => 'decimal:2',
            'window_cycle' => 'integer', 'filed_on_behalf' => 'boolean',
        ];
    }

    public function auditLabel(): string
    {
        return 'Expense report '.($this->getAttributes()['number'] ?? '');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE, true);
    }

    /** Approved (fully or partly), not paid, with money owed to the employee: what Pay expenses and payroll (EX-E) pick up. */
    public function isPayable(): bool
    {
        return in_array($this->status, ['approved', 'partly_approved'], true) && (float) $this->net_payable > 0 && $this->paid_on === null;
    }

    /** Payable and waiting for payroll (EX-E): the next run whose period covers the scheduling date pays it. */
    public function isScheduledForPayroll(): bool
    {
        return $this->isPayable() && $this->payment_channel === 'payroll';
    }

    /** Where the payment stands, as Pay expenses and the reports show it. */
    public function paymentStatus(): string
    {
        return match (true) {
            $this->paid_on !== null => 'Paid '.strtolower(self::CHANNELS[$this->payment_channel] ?? self::CHANNELS['bank']),
            $this->isScheduledForPayroll() => 'Scheduled for payroll',
            $this->isPayable() => 'Waiting for payment',
            default => 'Nothing to pay',
        };
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(ExpenseReceipt::class)->orderBy('expense_date')->orderBy('id');
    }

    /** EX-F: the trips the report covers (the trips of its expenses). */
    public function trips(): BelongsToMany
    {
        return $this->belongsToMany(TravelRequest::class, 'expense_report_travel')->withTimestamps();
    }

    public function parts(): HasMany
    {
        return $this->hasMany(ExpenseReportPart::class)->orderBy('id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
