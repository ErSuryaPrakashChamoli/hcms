<?php

namespace App\Domain\Compliance\Imports;

use App\Domain\Compliance\Models\EmployeePfOption;
use App\Domain\Compliance\Services\PfOptions;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;

/** HROne parity HP-3 (PY-30, PY-15): voluntary PF and PF wage basis per employee from a date, from a file. */
final class PfOptionImports extends RowImports
{
    public const TYPE = 'pf_options';

    public const MODES = [
        'both' => ['Add and change', 'Set options from the date given, replacing options already set from that date.'],
        'new' => ['Only new dates', 'Leave options already set from the same date as they are.'],
        'update' => ['Only dates already set', 'Change options already set from the same date only.'],
    ];

    public static function fields(): array
    {
        return ['employee_code' => ['Employee code', true], 'effective_from' => ['From', true], 'vpf_mode' => ['VPF mode', false], 'vpf_value' => ['VPF value', false], 'wage_basis' => ['PF wage basis', false], 'reason' => ['Reason', true]];
    }

    public function label(): string
    {
        return 'PF options (VPF) upload';
    }

    protected function module(): string
    {
        return 'payroll';
    }

    protected function entityType(): string
    {
        return EmployeePfOption::class;
    }

    protected function aliases(): array
    {
        return ['from' => 'effective_from', 'vpf_type' => 'vpf_mode', 'vpf' => 'vpf_value', 'vpf_percent' => 'vpf_value', 'basis' => 'wage_basis', 'remarks' => 'reason'];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        $from = $this->date($r['effective_from'] ?? null);
        if ($from === null) {
            $errors[] = 'From must be a date (YYYY-MM-DD).';
        }
        $mode = isset($r['vpf_mode']) ? strtolower($r['vpf_mode']) : null;
        if ($mode !== null && ! array_key_exists($mode, EmployeePfOption::VPF_MODES)) {
            $errors[] = 'VPF mode must be percent or amount (or blank for none).';
        }
        if ($mode !== null && (! is_numeric($r['vpf_value'] ?? null) || (float) $r['vpf_value'] <= 0 || ($mode === 'percent' && (float) $r['vpf_value'] > 100))) {
            $errors[] = 'VPF value must be above zero (a percent up to 100).';
        }
        if (isset($r['wage_basis']) && ! array_key_exists(strtolower($r['wage_basis']), EmployeePfOption::WAGE_BASES)) {
            $errors[] = 'PF wage basis must be ceiling or actual (or blank for the establishment rule).';
        }
        if ($errors !== []) {
            return ['action' => 'error', 'errors' => $errors, 'employee_id' => $employeeId];
        }

        return ['action' => EmployeePfOption::query()->where('employee_id', $employeeId)->whereDate('effective_from', $from)->exists() ? 'update' : 'create', 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $mode = isset($r['vpf_mode']) ? strtolower($r['vpf_mode']) : null;
        $option = app(PfOptions::class)->set(Employee::query()->findOrFail($row->employee_id), $this->date($r['effective_from']), $mode, $mode !== null ? (float) $r['vpf_value'] : null,
            isset($r['wage_basis']) ? strtolower($r['wage_basis']) : null, $r['reason'], $user);

        return $option->summary();
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Employee code', 'Yes', "The employee's code.", 'EMP0001'],
                ['From', 'Yes', 'The date the options apply from, YYYY-MM-DD.', now()->startOfMonth()->toDateString()],
                ['VPF mode', 'No', 'percent (of PF wages) or amount (a month); blank for no voluntary PF.', 'percent'],
                ['VPF value', 'No', 'The percent or the monthly amount.', '5'],
                ['PF wage basis', 'No', 'ceiling or actual; blank keeps the establishment rule.', ''],
                ['Reason', 'Yes', 'Why the options change.', 'Employee request'],
            ],
            'notes' => ['The latest options on or before a payroll month apply to it.'],
        ];
    }

    protected function examples(): array
    {
        $code = Employee::query()->orderBy('id')->value('employee_code') ?? 'EMP0001';

        return [[$code, now()->startOfMonth()->toDateString(), 'percent', '5', '', 'Employee request']];
    }
}
