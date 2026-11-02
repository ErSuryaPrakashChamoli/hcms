<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HROne parity PO-22: one employee's bonus in a bonus run — the statutory basis computed from finalized payroll under the
 * verified BONUS rule (BonusBasis), the statutory bonus still due, the ex gratia HR entered, and where it is on its way
 * to payroll: pending (not processed) → submitted (waiting for a second person's approval, with a payroll month) →
 * processed (paid by that month's payroll through BonusInput) → paid (settled when that payroll was finalized).
 */
#[Fillable(['tenant_id', 'bonus_run_id', 'employee_id', 'eligibility', 'reason', 'pay_group', 'months', 'from_month', 'to_month', 'bonus_days', 'wages', 'bonus_wages',
    'pay_rate', 'pay_rate_override', 'override_reason', 'bonus_rate', 'statutory_bonus', 'already_paid', 'bonus_amount', 'ex_gratia', 'ex_gratia_reason', 'included',
    'include_reason', 'status', 'pay_month', 'edited_by', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'return_note', 'paid_payroll_run_id', 'paid_at',
    'paid_bonus', 'paid_ex_gratia', 'basis'])]
class BonusRunLine extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const STATUSES = ['pending' => 'Not processed', 'submitted' => 'Waiting for approval', 'processed' => 'Processed', 'paid' => 'Paid'];

    public const ELIGIBILITY = ['eligible' => 'Eligible', 'review' => 'To decide', 'ineligible' => 'Not eligible', 'insufficient_data' => 'Insufficient data'];

    /** The lines of the "Non processed" tab and of the "Processed" tab. */
    public const NOT_PROCESSED = ['pending', 'submitted'];

    public const PROCESSED = ['processed', 'paid'];

    protected $attributes = ['status' => 'pending', 'included' => false];

    protected function casts(): array
    {
        return ['bonus_days' => 'decimal:2', 'wages' => 'decimal:2', 'bonus_wages' => 'decimal:2', 'pay_rate' => 'decimal:2', 'pay_rate_override' => 'decimal:2',
            'bonus_rate' => 'decimal:6', 'statutory_bonus' => 'decimal:2', 'already_paid' => 'decimal:2', 'bonus_amount' => 'decimal:2', 'ex_gratia' => 'decimal:2',
            'paid_bonus' => 'decimal:2', 'paid_ex_gratia' => 'decimal:2', 'included' => 'boolean', 'basis' => 'array',
            'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'payroll';
    }

    public function auditLabel(): string
    {
        return 'Bonus line'.($this->pay_month ? " ({$this->pay_month})" : '');
    }

    public function auditSensitiveAttributes(): array
    {
        return ['wages', 'bonus_wages', 'pay_rate', 'pay_rate_override', 'statutory_bonus', 'already_paid', 'bonus_amount', 'ex_gratia', 'paid_bonus', 'paid_ex_gratia'];
    }

    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'basis'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(BonusRun::class, 'bonus_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function paidRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'paid_payroll_run_id');
    }

    /** What the line pays: the statutory bonus still due plus the ex gratia. */
    public function total(): float
    {
        return round((float) $this->bonus_amount + (float) $this->ex_gratia, 2);
    }
}
