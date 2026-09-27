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

    protected static function booted(): void
    {
        // Phase 4 §32: finalized payroll is history; corrections go through arrears, recoveries or a reopen.
        $guard = function (self $model): void {
            if (in_array(PayrollRun::query()->whereKey(PayrollEntry::query()->whereKey($model->payroll_entry_id)->value('payroll_run_id'))->value('status'), ['finalized', 'paid'], true)) {
                throw new \RuntimeException('Entries of finalized payroll cannot be changed; use an arrear, a recovery or reopen the run.');
            }
        };
        static::updating($guard);
        static::deleting($guard);
    }

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
