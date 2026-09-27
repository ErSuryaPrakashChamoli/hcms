<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A one-off input for a period: extra earning, extra deduction, or manual LOP days (§31). */
#[Fillable(['tenant_id', 'employee_id', 'payroll_period_id', 'salary_component_id', 'type', 'name', 'amount', 'taxable', 'note', 'created_by'])]
class PayrollAdjustment extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const TYPES = ['earning' => 'Additional earning', 'deduction' => 'Additional deduction', 'lop' => 'Loss of pay (days)', 'reimbursement' => 'Reimbursement (non-taxable)'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'taxable' => 'boolean'];
    }

    public function auditModule(): string
    {
        return 'payroll';
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->type})";
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class, 'salary_component_id');
    }
}
