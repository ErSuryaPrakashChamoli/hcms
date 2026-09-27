<?php

namespace App\Domain\Payroll\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A computed component amount with its basis (formula, wages, rule version) for traceability. */
#[Fillable(['tenant_id', 'payroll_entry_id', 'salary_component_id', 'code', 'name', 'type', 'classification', 'amount', 'taxable', 'basis', 'sort_order'])]
class PayrollEntryLine extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'taxable' => 'boolean', 'basis' => 'array', 'sort_order' => 'integer'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(PayrollEntry::class, 'payroll_entry_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class, 'salary_component_id');
    }

    public function isDeduction(): bool
    {
        return $this->type === 'deduction';
    }

    public function isEmployerContribution(): bool
    {
        return $this->type === 'employer_contribution';
    }
}
