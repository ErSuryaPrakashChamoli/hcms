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
 */
#[Fillable(['tenant_id', 'employee_id', 'financial_year', 'kind', 'perquisite_type', 'description', 'paid_on', 'amount', 'recovered', 'taxable_amount', 'tds_deducted', 'pf_employee', 'professional_tax', 'reference', 'status', 'created_by', 'cancelled_by', 'cancelled_at', 'cancel_reason'])]
class TaxIncomeEntry extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const KINDS = ['perquisite' => 'Perquisite', 'outside_payroll' => 'Paid outside payroll'];

    public const STATUSES = ['active' => 'Active', 'cancelled' => 'Cancelled'];

    protected $attributes = ['status' => 'active', 'recovered' => 0, 'tds_deducted' => 0, 'pf_employee' => 0, 'professional_tax' => 0];

    protected function casts(): array
    {
        return [
            'paid_on' => 'date', 'amount' => 'decimal:2', 'recovered' => 'decimal:2', 'taxable_amount' => 'decimal:2',
            'tds_deducted' => 'decimal:2', 'pf_employee' => 'decimal:2', 'professional_tax' => 'decimal:2', 'cancelled_at' => 'datetime',
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
