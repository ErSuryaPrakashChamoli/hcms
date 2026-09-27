<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Support\EffectiveDating\HasEffectiveDates;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One row of an employee's salary history (§70, §100). Sensitive; CTC is masked in audit. */
#[Fillable(['tenant_id', 'employee_id', 'salary_structure_id', 'ctc_annual', 'currency', 'component_values', 'change_type', 'effective_from', 'effective_to', 'reason', 'created_by'])]
class EmployeeSalaryAssignment extends Model
{
    use Auditable, BelongsToTenant, HasEffectiveDates;

    public const CHANGE_TYPES = ['hire' => 'Hire', 'revision' => 'Revision', 'promotion' => 'Promotion', 'correction' => 'Correction', 'transfer' => 'Transfer'];

    protected function casts(): array
    {
        return [
            'ctc_annual' => 'decimal:2',
            'component_values' => 'array',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function auditModule(): string
    {
        return 'payroll';
    }

    public function auditLabel(): string
    {
        return 'Salary from '.$this->effective_from?->toDateString();
    }

    public function auditSensitiveAttributes(): array
    {
        return ['ctc_annual', 'component_values'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class, 'salary_structure_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function monthlyCtc(): float
    {
        return round((float) $this->ctc_annual / 12, 2);
    }

    /** Monthly fixed value for a component code (component_values are stored monthly). */
    public function valueFor(string $code): float
    {
        return (float) data_get($this->component_values, strtoupper($code), 0);
    }
}
