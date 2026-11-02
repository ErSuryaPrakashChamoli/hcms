<?php

namespace App\Domain\Compliance\Imports;

use App\Domain\Compliance\Models\TaxIncomeEntry;
use App\Domain\Compliance\Services\TaxIncomeEntries;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Imports\PayrollImportCells;
use Illuminate\Support\Carbon;

/**
 * Data import DI-4 (Payroll → Previous month salary): the months paid before PeopleOS went live this financial year,
 * one employee and month per row, recorded through TaxIncomeEntries (kind previous_salary). Salary TDS counts the
 * taxable pay, the tax, PF and PT already deducted and the basic + DA and HRA (HRA exemption) like income paid outside
 * payroll. A month PeopleOS paid itself is refused; a month already recorded is replaced (update).
 */
final class PreviousSalaryImports extends RowImports
{
    use PayrollImportCells;

    public const TYPE = 'previous_salaries';

    public static function fields(): array
    {
        return [
            'employee_code' => ['Employee code', true], 'pay_month' => ['Salary month', true], 'gross' => ['Gross earnings', true], 'taxable' => ['Taxable earnings', false],
            'basic_da' => ['Basic + DA', false], 'hra' => ['HRA', false], 'tds' => ['Income tax deducted', false], 'pf' => ['Employee PF deducted', false],
            'pt' => ['Professional tax deducted', false], 'paid_on' => ['Paid on', false], 'reference' => ['Reference', false],
        ];
    }

    public function label(): string
    {
        return 'Previous month salary upload';
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
        return ['month' => 'pay_month', 'payroll_month' => 'pay_month', 'gross_salary' => 'gross', 'taxable_salary' => 'taxable', 'basic' => 'basic_da',
            'tds_deducted' => 'tds', 'income_tax' => 'tds', 'pf_employee' => 'pf', 'professional_tax' => 'pt', 'payment_date' => 'paid_on'];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        $month = $this->month($r['pay_month'] ?? null);
        if ($month === null) {
            $errors[] = 'Salary month must look like 2026-06.';
        } elseif ($month > now()->format('Y-m')) {
            $errors[] = 'Salary month cannot be a month that has not started.';
        }
        $gross = $this->money($r['gross'] ?? null);
        if ($gross === null || $gross <= 0) {
            $errors[] = 'Gross earnings must be a number above zero.';
        }
        $values = [];
        foreach (['taxable' => 'Taxable earnings', 'basic_da' => 'Basic + DA', 'hra' => 'HRA', 'tds' => 'Income tax deducted', 'pf' => 'Employee PF deducted', 'pt' => 'Professional tax deducted'] as $field => $label) {
            $values[$field] = isset($r[$field]) ? $this->money($r[$field]) : 0.0;
            if ($values[$field] === null || $values[$field] < 0) {
                $errors[] = "{$label} must be a number, zero or more.";
            }
        }
        $taxable = isset($r['taxable']) ? $values['taxable'] : $gross;
        if ($gross !== null && $taxable !== null && $taxable > $gross) {
            $errors[] = 'Taxable earnings cannot be more than the gross.';
        }
        if ($gross !== null && ($values['basic_da'] ?? 0) + ($values['hra'] ?? 0) > $gross) {
            $errors[] = 'Basic + DA and HRA cannot add up to more than the gross.';
        }
        if (isset($r['paid_on']) && $this->date($r['paid_on']) === null) {
            $errors[] = 'Paid on must be a date (YYYY-MM-DD).';
        }
        if ($errors !== []) {
            return ['action' => 'error', 'errors' => $errors, 'employee_id' => $employeeId];
        }

        $employee = Employee::query()->findOrFail($employeeId);
        $first = Carbon::parse($month.'-01');
        if (app(TaxIncomeEntries::class)->paidByPayroll($employee, $first)) {
            return ['action' => 'error', 'errors' => ['PeopleOS has already paid '.$first->format('F Y').' to this employee; only months before going live are recorded.'], 'employee_id' => $employeeId];
        }
        if (($duplicate = $this->seenBefore($employeeId.'|'.$month, $context, $row->row_number, 'employee and month')) !== null) {
            return ['action' => 'error', 'errors' => [$duplicate], 'employee_id' => $employeeId];
        }

        $existing = TaxIncomeEntry::query()->active()->where('employee_id', $employeeId)->where('kind', 'previous_salary')->whereDate('pay_month', $first->toDateString())->first();
        if ($existing === null) {
            return ['action' => 'create', 'employee_id' => $employeeId];
        }
        $paidOn = $this->date($r['paid_on'] ?? null) ?? $first->copy()->endOfMonth()->toDateString();
        $same = (float) $existing->amount === $gross && (float) $existing->taxable_amount === $taxable && (float) $existing->basic_da === $values['basic_da'] && (float) $existing->hra === $values['hra']
            && (float) $existing->tds_deducted === $values['tds'] && (float) $existing->pf_employee === $values['pf'] && (float) $existing->professional_tax === $values['pt']
            && $existing->paid_on?->toDateString() === $paidOn;

        return $same
            ? ['action' => 'skip', 'employee_id' => $employeeId, 'match' => ['reason' => $first->format('F Y').' is already recorded with these amounts.']]
            : ['action' => 'update', 'employee_id' => $employeeId, 'match' => ['reason' => 'Replaces '.$first->format('F Y').' recorded earlier.']];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $gross = (float) $this->money($r['gross']);
        $entry = app(TaxIncomeEntries::class)->recordPreviousSalary(Employee::query()->findOrFail($row->employee_id), $this->month($r['pay_month']), $this->date($r['paid_on'] ?? null),
            $gross, isset($r['taxable']) ? (float) $this->money($r['taxable']) : $gross, (float) ($this->money($r['basic_da'] ?? null) ?? 0), (float) ($this->money($r['hra'] ?? null) ?? 0),
            (float) ($this->money($r['tds'] ?? null) ?? 0), (float) ($this->money($r['pf'] ?? null) ?? 0), (float) ($this->money($r['pt'] ?? null) ?? 0),
            $user, $r['reference'] ?? $this->label()." #{$import->getKey()}");

        return $entry->pay_month->format('F Y')." recorded for {$entry->financial_year}";
    }

    public function format(): array
    {
        [$code] = $this->sampleCodes(1);
        $month = now()->subMonths(2)->format('Y-m');

        return [
            'columns' => [
                ['Employee code', 'Yes', "The employee's code.", $code],
                ['Salary month', 'Yes', 'The month the salary was for, YYYY-MM. A month PeopleOS has paid is refused.', $month],
                ['Gross earnings', 'Yes', 'Everything earned in the month on the previous system.', '85000'],
                ['Taxable earnings', 'No', 'Blank: all of the gross is taxable.', '82000'],
                ['Basic + DA', 'No', 'Basic pay and dearness allowance of the month (for the HRA exemption).', '40000'],
                ['HRA', 'No', 'House rent allowance of the month (for the HRA exemption).', '20000'],
                ['Income tax deducted', 'No', 'TDS deducted from this month\'s salary.', '6500'],
                ['Employee PF deducted', 'No', 'Counts under 80C like payroll PF.', '4800'],
                ['Professional tax deducted', 'No', 'Counts like payroll PT.', '200'],
                ['Paid on', 'No', 'Payment date, YYYY-MM-DD; blank = the last day of the month. It decides the financial year.', ''],
                ['Reference', 'No', 'Your reference.', ''],
            ],
            'notes' => [
                'One row per employee and month. A month already recorded is replaced (choose Update existing records or Insert and update); the same amounts are skipped.',
                'Salary TDS counts these months from the next calculation, like income paid outside payroll. They are not payslips: payroll registers and My salary YTD show PeopleOS months only.',
                'Cancel a wrong month on Payroll → Perquisites and other income.',
            ],
        ];
    }

    protected function examples(): array
    {
        [$first, $second] = $this->sampleCodes(2);
        $month = now()->subMonths(2)->format('Y-m');

        return [
            [$first, $month, '85000', '82000', '40000', '20000', '6500', '4800', '200', '', 'Old payroll'],
            [$second, $month, '60000', '60000', '30000', '15000', '2100', '3600', '200', '', 'Old payroll'],
        ];
    }
}
