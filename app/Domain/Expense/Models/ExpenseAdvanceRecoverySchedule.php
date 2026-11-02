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

/**
 * HROne parity XO-6 (owner decision D-XO2 / EXP-8): a recovery of a dispensed expense advance that HR scheduled for a chosen
 * payroll month and amount ("Recover through payroll" on the Advance recovery desk). While an advance has an open schedule
 * (`scheduled`), payroll recovers it only in the scheduled months — the automatic recovery date of its policy waits — and at
 * most what is outstanding. A schedule is recovered once (`recovered`: the run or final settlement, the amount and the date),
 * cancelled by finance until a payroll run carries the advance (`cancelled`), or closed with its advance when that is settled,
 * recovered or written off first (`closed`). A month finalized without it leaves it scheduled and flagged (see
 * AdvanceRecoveries::missed) until finance moves or cancels it.
 */
#[Fillable([
    'tenant_id', 'expense_advance_id', 'employee_id', 'recover_in', 'amount', 'status', 'note', 'scheduled_by',
    'recovered_amount', 'recovered_on', 'payroll_run_id', 'final_settlement_id', 'cancelled_at', 'cancelled_by', 'cancel_reason',
])]
class ExpenseAdvanceRecoverySchedule extends Model
{
    use Auditable, BelongsToTenant, ScopedByEmployee;

    public const STATUSES = ['scheduled' => 'Scheduled', 'recovered' => 'Recovered', 'cancelled' => 'Cancelled', 'closed' => 'Closed with the advance'];

    protected $attributes = ['status' => 'scheduled'];

    protected function casts(): array
    {
        return [
            'recover_in' => 'date', 'amount' => 'decimal:2', 'recovered_amount' => 'decimal:2', 'recovered_on' => 'date', 'cancelled_at' => 'datetime',
        ];
    }

    public function auditModule(): string
    {
        return 'expense';
    }

    public function auditLabel(): string
    {
        return 'Advance recovery schedule #'.($this->getAttributes()['id'] ?? '');
    }

    public function isOpen(): bool
    {
        return $this->status === 'scheduled';
    }

    /** The payroll month as payroll names it ("2026-11"). */
    public function month(): string
    {
        return $this->recover_in->format('Y-m');
    }

    public function monthLabel(): string
    {
        return $this->recover_in->format('M Y');
    }

    public function advance(): BelongsTo
    {
        return $this->belongsTo(ExpenseAdvance::class, 'expense_advance_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function scheduler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scheduled_by');
    }
}
