<?php

namespace App\Domain\Compliance\Imports;

use App\Domain\Compliance\Models\TaxIncomeEntry;
use App\Domain\Compliance\Services\TaxIncomeEntries;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;

/**
 * HROne parity HP-3 (PY-30, PY-25): income paid outside this payroll from a file — out-of-salary payments, or the
 * months paid on another system before going live mid-year — with the tax, PF and PT already deducted on it.
 */
final class OutsideIncomeImports extends RowImports
{
    public const TYPE = 'outside_income';

    public static function fields(): array
    {
        return ['employee_code' => ['Employee code', true], 'paid_on' => ['Paid on', true], 'description' => ['Description', true], 'amount' => ['Amount paid', true],
            'taxable' => ['Taxable part', false], 'tds' => ['Income tax deducted', false], 'pf' => ['Employee PF deducted', false], 'pt' => ['Professional tax deducted', false], 'reference' => ['Reference', false]];
    }

    public function label(): string
    {
        return 'Income outside payroll upload';
    }

    protected function module(): string
    {
        return 'compliance';
    }

    protected function entityType(): string
    {
        return TaxIncomeEntry::class;
    }

    protected function aliases(): array
    {
        return ['date' => 'paid_on', 'payment_date' => 'paid_on', 'gross' => 'amount', 'taxable_amount' => 'taxable', 'tds_deducted' => 'tds', 'pf_employee' => 'pf', 'professional_tax' => 'pt'];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        if ($this->date($r['paid_on'] ?? null) === null) {
            $errors[] = 'Paid on must be a date (YYYY-MM-DD).';
        }
        $amount = $r['amount'] ?? null;
        if (! is_numeric($amount) || (float) $amount <= 0) {
            $errors[] = 'Amount paid must be a number above zero.';
        } elseif (isset($r['taxable']) && (! is_numeric($r['taxable']) || (float) $r['taxable'] < 0 || (float) $r['taxable'] > (float) $amount)) {
            $errors[] = 'The taxable part must be between zero and the amount paid.';
        }
        foreach (['tds' => 'Income tax deducted', 'pf' => 'Employee PF deducted', 'pt' => 'Professional tax deducted'] as $key => $label) {
            if (isset($r[$key]) && (! is_numeric($r[$key]) || (float) $r[$key] < 0)) {
                $errors[] = "{$label} must be a number, zero or more.";
            }
        }

        return $errors !== [] ? ['action' => 'error', 'errors' => $errors, 'employee_id' => $employeeId] : ['action' => 'create', 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $amount = (float) $r['amount'];
        $entry = app(TaxIncomeEntries::class)->recordOutside(Employee::query()->findOrFail($row->employee_id), $this->date($r['paid_on']), $r['description'], $amount, (float) ($r['taxable'] ?? $amount),
            (float) ($r['tds'] ?? 0), (float) ($r['pf'] ?? 0), (float) ($r['pt'] ?? 0), $user, $r['reference'] ?? $this->label()." #{$import->getKey()}");

        return "Recorded in {$entry->financial_year}";
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Employee code', 'Yes', "The employee's code.", 'EMP0001'],
                ['Paid on', 'Yes', 'Payment date, YYYY-MM-DD; it decides the financial year.', now()->subMonth()->startOfMonth()->toDateString()],
                ['Description', 'Yes', 'What was paid.', 'Salary April–June on the previous system'],
                ['Amount paid', 'Yes', 'Gross amount paid.', '150000'],
                ['Taxable part', 'No', 'Blank: all of it is taxable.', '150000'],
                ['Income tax deducted', 'No', 'TDS already deducted on it.', '6000'],
                ['Employee PF deducted', 'No', 'Counts under 80C like payroll PF.', '5400'],
                ['Professional tax deducted', 'No', 'Counts like payroll PT.', '600'],
                ['Reference', 'No', 'Your reference.', ''],
            ],
            'notes' => ['Each row adds an entry; cancel a wrong one on Payroll → Perquisites and other income.', 'TDS counts the income and the tax already deducted from the next calculation.'],
        ];
    }

    protected function examples(): array
    {
        $code = Employee::query()->orderBy('id')->value('employee_code') ?? 'EMP0001';

        return [[$code, now()->subMonth()->startOfMonth()->toDateString(), 'Salary April–June on the previous system', '150000', '150000', '6000', '5400', '600', '']];
    }
}
