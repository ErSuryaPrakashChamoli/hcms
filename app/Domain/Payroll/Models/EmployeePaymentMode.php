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
 * HROne setup parity BP-13: how an employee is paid from a date — salary by bank transfer, cash or cheque, optionally from
 * a chosen paying account, and reimbursements the same way or differently (null = as the salary). The latest row on or
 * before the pay period's end applies; an employee without a row is paid by bank transfer, as before.
 */
#[Fillable(['tenant_id', 'employee_id', 'effective_from', 'salary_mode', 'salary_bank_account_id', 'reimbursement_mode', 'reimbursement_bank_account_id', 'reason', 'created_by'])]
class EmployeePaymentMode extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const MODES = ['online_transfer' => 'Bank transfer', 'cash' => 'Cash', 'cheque' => 'Cheque'];

    protected function casts(): array
    {
        return ['effective_from' => 'date'];
    }

    public function auditModule(): string
    {
        return 'payroll';
    }

    public function auditLabel(): string
    {
        return 'Payment mode from '.$this->effective_from?->toDateString();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function salaryAccount(): BelongsTo
    {
        return $this->belongsTo(CompanyBankAccount::class, 'salary_bank_account_id');
    }

    public function reimbursementAccount(): BelongsTo
    {
        return $this->belongsTo(CompanyBankAccount::class, 'reimbursement_bank_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** How reimbursements are paid: their own mode, else the salary's. */
    public function reimbursementMode(): string
    {
        return $this->reimbursement_mode ?: $this->salary_mode;
    }

    public function summary(): string
    {
        $salary = self::MODES[$this->salary_mode] ?? $this->salary_mode;
        $reimbursement = $this->reimbursement_mode !== null && $this->reimbursement_mode !== $this->salary_mode ? ' · reimbursements by '.strtolower(self::MODES[$this->reimbursement_mode] ?? $this->reimbursement_mode) : '';

        return 'Salary by '.strtolower($salary).$reimbursement;
    }
}
