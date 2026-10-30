<?php

namespace App\Domain\Compliance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee's own PF choices from a date (HROne parity PY-15): voluntary PF on top of the statutory share (a percent
 * of PF wages or a fixed amount a month) and whether their PF is worked out on the wage ceiling or on actual wages
 * (null keeps the establishment's rule). The latest row on or before the period end applies.
 */
#[Fillable(['tenant_id', 'employee_id', 'effective_from', 'vpf_mode', 'vpf_value', 'wage_basis', 'reason', 'created_by'])]
class EmployeePfOption extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const VPF_MODES = ['percent' => 'Percent of PF wages', 'amount' => 'Fixed amount a month'];

    public const WAGE_BASES = ['ceiling' => 'Up to the wage ceiling', 'actual' => 'On actual PF wages'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'vpf_value' => 'decimal:2'];
    }

    public function auditModule(): string
    {
        return 'payroll';
    }

    public function auditLabel(): string
    {
        return 'PF options from '.$this->effective_from?->toDateString();
    }

    public function summary(): string
    {
        $vpf = match ($this->vpf_mode) {
            'percent' => 'VPF '.rtrim(rtrim(number_format((float) $this->vpf_value, 2), '0'), '.').'% of PF wages',
            'amount' => 'VPF '.number_format((float) $this->vpf_value, 2).' a month',
            default => 'No VPF',
        };

        return $vpf.' · '.(self::WAGE_BASES[$this->wage_basis] ?? 'Establishment rule');
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
