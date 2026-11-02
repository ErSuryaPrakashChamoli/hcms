<?php

namespace App\Domain\Compliance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Income the salary TDS projection must include although this payroll does not pay it (HROne parity PY-07, PY-17,
 * PY-18, PY-25): a perquisite's taxable value for the year, or a payment made outside the run (an out-of-salary
 * payment, or months paid on another system before going live) with the tax, PF and PT already deducted on it.
 * Data import DI-4 adds a month paid before PeopleOS went live with its basic + DA and HRA (previous_salary), and tax
 * deducted or collected elsewhere that the employee declares on Form 12BAA (tax_credit).
 */
#[Fillable(['tenant_id', 'employee_id', 'financial_year', 'kind', 'perquisite_type', 'description', 'paid_on', 'amount', 'recovered', 'taxable_amount', 'tds_deducted', 'pf_employee', 'professional_tax', 'reference', 'status', 'created_by', 'cancelled_by', 'cancelled_at', 'cancel_reason',
    // Data import DI-4: previous month salary (pay month, basic + DA, HRA) and Form 12BAA tax credits (TDS / TCS, provision, deductor).
    'pay_month', 'basic_da', 'hra', 'credit_type', 'section', 'deductor_name', 'deductor_tan'])]
class TaxIncomeEntry extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const KINDS = ['perquisite' => 'Perquisite', 'outside_payroll' => 'Paid outside payroll', 'previous_salary' => 'Salary before PeopleOS', 'tax_credit' => 'TDS / TCS declared (12BAA)'];

    /** Data import DI-4: what a Form 12BAA tax credit was (tax deducted at source, or tax collected at source). */
    public const CREDIT_TYPES = ['tds' => 'Tax deducted at source (TDS)', 'tcs' => 'Tax collected at source (TCS)'];

    public const STATUSES = ['active' => 'Active', 'cancelled' => 'Cancelled'];

    protected $attributes = ['status' => 'active', 'recovered' => 0, 'tds_deducted' => 0, 'pf_employee' => 0, 'professional_tax' => 0];

    protected function casts(): array
    {
        return [
            'paid_on' => 'date', 'amount' => 'decimal:2', 'recovered' => 'decimal:2', 'taxable_amount' => 'decimal:2',
            'tds_deducted' => 'decimal:2', 'pf_employee' => 'decimal:2', 'professional_tax' => 'decimal:2', 'cancelled_at' => 'datetime',
            'pay_month' => 'date', 'basic_da' => 'decimal:2', 'hra' => 'decimal:2',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function auditModule(): string
    {
        return 'compliance';
    }

    public function auditLabel(): string
    {
        return (self::KINDS[$this->kind] ?? $this->kind)." {$this->financial_year}: {$this->description}";
    }

    public function typeLabel(): string
    {
        return $this->kind === 'perquisite' ? (config("peopleos.compliance.perquisite_types.{$this->perquisite_type}") ?? (string) $this->perquisite_type) : (self::KINDS[$this->kind] ?? $this->kind);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
