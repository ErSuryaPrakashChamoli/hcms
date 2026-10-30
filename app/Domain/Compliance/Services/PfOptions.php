<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Models\EmployeePfOption;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use Carbon\CarbonInterface;
use RuntimeException;

/** Employee-level PF choices (HROne parity PY-15): voluntary PF and the PF wage basis, effective-dated and audited. */
final class PfOptions
{
    public function set(Employee $employee, string $effectiveFrom, ?string $vpfMode, ?float $vpfValue, ?string $wageBasis, string $reason, User $by): EmployeePfOption
    {
        if (! $by->can('payroll.manage')) {
            throw new RuntimeException('Only payroll can change PF options.');
        }
        if ($vpfMode !== null && ! array_key_exists($vpfMode, EmployeePfOption::VPF_MODES)) {
            throw new RuntimeException('Choose how voluntary PF is worked out.');
        }
        if ($vpfMode !== null && ($vpfValue === null || $vpfValue <= 0 || ($vpfMode === 'percent' && $vpfValue > 100))) {
            throw new RuntimeException($vpfMode === 'percent' ? 'Voluntary PF must be a percent above 0 and up to 100.' : 'Voluntary PF must be an amount above zero.');
        }
        if ($wageBasis !== null && ! array_key_exists($wageBasis, EmployeePfOption::WAGE_BASES)) {
            throw new RuntimeException('Choose the PF wage basis from the list.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give the reason for the change.');
        }

        $option = EmployeePfOption::query()->where('employee_id', $employee->id)->whereDate('effective_from', $effectiveFrom)->first()
            ?? new EmployeePfOption(['employee_id' => $employee->id, 'effective_from' => $effectiveFrom]);
        $option->fill(['vpf_mode' => $vpfMode, 'vpf_value' => $vpfMode === null ? null : round((float) $vpfValue, 2), 'wage_basis' => $wageBasis, 'reason' => trim($reason), 'created_by' => $by->id]);
        $option->withAuditReason($reason)->save();

        return $option;
    }

    public function for(int $employeeId, CarbonInterface $on): ?EmployeePfOption
    {
        return EmployeePfOption::query()->where('employee_id', $employeeId)->whereDate('effective_from', '<=', $on->toDateString())->orderByDesc('effective_from')->first();
    }
}
