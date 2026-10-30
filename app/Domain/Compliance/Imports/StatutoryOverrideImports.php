<?php

namespace App\Domain\Compliance\Imports;

use App\Domain\Compliance\Models\StatutoryOverride;
use App\Domain\Compliance\Services\StatutoryOverrides;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Models\PayrollPeriod;
use RuntimeException;

/** HROne parity HP-3 (PY-30): audited statutory overrides from a file, one employee, pay month and line per row. */
final class StatutoryOverrideImports extends RowImports
{
    public const TYPE = 'stat_overrides';

    public static function fields(): array
    {
        return ['employee_code' => ['Employee code', true], 'pay_month' => ['Payroll month', true], 'line' => ['Line', true], 'amount' => ['Amount', true], 'reason' => ['Reason', true]];
    }

    public function label(): string
    {
        return 'Statutory override upload';
    }

    protected function module(): string
    {
        return 'payroll';
    }

    protected function entityType(): string
    {
        return StatutoryOverride::class;
    }

    protected function aliases(): array
    {
        return ['month' => 'pay_month', 'code' => 'line', 'component' => 'line', 'remarks' => 'reason'];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($r['pay_month'] ?? ''))) {
            $errors[] = 'Payroll month must look like 2026-10.';
        }
        if (! array_key_exists(strtoupper((string) ($r['line'] ?? '')), StatutoryOverride::CODES)) {
            $errors[] = 'Line must be one of '.implode(', ', array_keys(StatutoryOverride::CODES)).'.';
        }
        if (! is_numeric($r['amount'] ?? null) || (float) $r['amount'] < 0) {
            $errors[] = 'Amount must be a number, zero or more (zero removes the line).';
        }
        if ($errors !== []) {
            return ['action' => 'error', 'errors' => $errors, 'employee_id' => $employeeId];
        }
        $key = $employeeId.'|'.$r['pay_month'].'|'.strtoupper($r['line']);
        if (isset($context['seen'][$key])) {
            return ['action' => 'error', 'errors' => ['The same employee, month and line are in row '.$context['seen'][$key].'.'], 'employee_id' => $employeeId];
        }
        $context['seen'][$key] = $row->row_number;

        return ['action' => 'create', 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $employee = Employee::query()->with('currentPosition.company')->findOrFail($row->employee_id);
        $company = $employee->currentPosition?->company ?? throw new RuntimeException('The employee has no current position, so their company is not known.');
        [$year, $month] = array_map('intval', explode('-', $r['pay_month']));
        $override = app(StatutoryOverrides::class)->set($employee, PayrollPeriod::for($company, $year, $month), strtoupper($r['line']), (float) $r['amount'], $r['reason'], $user);

        return "{$override->codeLabel()} set to ".number_format((float) $override->amount, 2);
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Employee code', 'Yes', "The employee's code.", 'EMP0001'],
                ['Payroll month', 'Yes', 'YYYY-MM; the employee\'s pay for it must not be finalised.', now()->format('Y-m')],
                ['Line', 'Yes', 'The statutory line code.', 'TDS'],
                ['Amount', 'Yes', 'The amount to use; 0 removes the line.', '2500'],
                ['Reason', 'Yes', 'Why the calculated amount is overridden; kept on the audit trail.', 'Tax already paid by the employee'],
            ],
            'codes' => collect(StatutoryOverride::CODES)->map(fn ($c) => $c[0])->all(),
            'codes_title' => 'Line codes',
            'notes' => ['A new override of the same line and month replaces the earlier one.', 'Recalculate the run after the upload for the overrides to apply.'],
        ];
    }

    protected function examples(): array
    {
        $code = Employee::query()->orderBy('id')->value('employee_code') ?? 'EMP0001';

        return [[$code, now()->format('Y-m'), 'TDS', '2500', 'Tax already paid by the employee']];
    }
}
