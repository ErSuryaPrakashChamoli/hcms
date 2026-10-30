<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Compliance\Models\EmployeePfOption;
use Illuminate\Database\Eloquent\Builder;

/** HROne parity HP-3: employee PF options — voluntary PF and the PF wage basis, by date. */
class PfOptionsDataset extends Dataset
{
    public function key(): string
    {
        return 'pf_options';
    }

    public function label(): string
    {
        return 'PF options (VPF)';
    }

    public function category(): string
    {
        return 'Payroll & compensation';
    }

    public function permissions(): array
    {
        return ['payroll.manage'];
    }

    public function fields(): array
    {
        return [
            'effective_from' => ['label' => 'From', 'type' => 'date', 'value' => fn ($r) => $r->effective_from],
            ...$this->employeeFields(fn ($r) => $r->employee),
            'vpf_mode' => ['label' => 'VPF mode', 'type' => 'string', 'value' => fn ($r) => EmployeePfOption::VPF_MODES[$r->vpf_mode] ?? 'None'],
            'vpf_value' => ['label' => 'VPF value', 'type' => 'number', 'value' => fn ($r) => $r->vpf_value === null ? null : (float) $r->vpf_value],
            'wage_basis' => ['label' => 'PF wages', 'type' => 'string', 'value' => fn ($r) => EmployeePfOption::WAGE_BASES[$r->wage_basis] ?? 'Establishment rule'],
            'reason' => ['label' => 'Reason', 'type' => 'string', 'value' => fn ($r) => $r->reason],
            'count' => ['label' => 'Rows (1 per row)', 'type' => 'number', 'value' => fn ($r) => 1],
        ];
    }

    public function query(): Builder
    {
        return EmployeePfOption::query()->with(self::employeeWith('employee'))->orderByDesc('effective_from');
    }
}
