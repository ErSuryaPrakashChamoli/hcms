<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** One employee's result within a run: totals, exceptions and the inputs used (§31 traceability). */
#[Fillable(['tenant_id', 'payroll_run_id', 'employee_id', 'employee_salary_assignment_id', 'days_in_period', 'paid_days', 'lop_days', 'gross', 'total_earnings', 'total_deductions', 'net_pay', 'employer_cost', 'taxable_earnings', 'status', 'exceptions', 'inputs'])]
class PayrollEntry extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return [
            'days_in_period' => 'integer',
            'paid_days' => 'decimal:2',
            'lop_days' => 'decimal:2',
            'gross' => 'decimal:2',
            'total_earnings' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'net_pay' => 'decimal:2',
            'employer_cost' => 'decimal:2',
            'taxable_earnings' => 'decimal:2',
            'exceptions' => 'array',
            'inputs' => 'array',
        ];
    }

    public function auditModule(): string
    {
        return 'payroll';
    }

    public function auditLabel(): string
    {
        return 'Payroll entry #'.$this->id;
    }

    public function auditSensitiveAttributes(): array
    {
        return ['gross', 'total_earnings', 'total_deductions', 'net_pay', 'employer_cost', 'taxable_earnings', 'inputs'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeSalaryAssignment::class, 'employee_salary_assignment_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollEntryLine::class)->orderBy('sort_order');
    }

    public function payslip(): HasOne
    {
        return $this->hasOne(Payslip::class);
    }

    public function line(string $code): ?PayrollEntryLine
    {
        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();

        return $lines->firstWhere('code', $code);
    }

    public function amount(string $code): float
    {
        return (float) ($this->line($code)?->amount ?? 0);
    }

    public function hasBlockingException(): bool
    {
        return $this->status === 'exception';
    }
}
