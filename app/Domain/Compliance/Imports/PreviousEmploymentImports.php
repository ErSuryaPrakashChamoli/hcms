<?php

namespace App\Domain\Compliance\Imports;

use App\Domain\Compliance\Services\FinancialYear;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Carbon;

/**
 * Data import DI-4 (Workforce → Previous employment): income, tax, PF and PT from an employer the employee worked for
 * earlier in the financial year (Form 12B), one employee and year per row, saved as the declaration's previous employer
 * — the "Previous job" step of the guided declaration — through InvestmentDeclarations. TDS counts the taxable salary
 * and tax there; PF in 80C and PT in the PT deduction (old regime); the exemptions used there only through verified
 * rule limits. Details stay encrypted on the declaration.
 */
final class PreviousEmploymentImports extends DeclarationRowImports
{
    public const TYPE = 'previous_employment';

    private const AMOUNTS = ['income' => 'Taxable salary there', 'tds' => 'Income tax deducted there', 'pf' => 'PF deducted there', 'pt' => 'Professional tax deducted there',
        'gratuity_exempt' => 'Gratuity exemption used there', 'leave_encashment_exempt' => 'Leave encashment exemption used there'];

    public static function fields(): array
    {
        return [
            'employee_code' => ['Employee code', true], 'financial_year' => ['Financial year', true], 'name' => ['Previous employer', true], 'tan' => ['Their TAN', false],
            'from' => ['Worked there from', false], 'to' => ['Worked there until', false], 'income' => ['Taxable salary there', true], 'tds' => ['Income tax deducted there', true],
            'pf' => ['PF deducted there', false], 'pt' => ['Professional tax deducted there', false], 'gratuity_exempt' => ['Gratuity exemption used there', false],
            'leave_encashment_exempt' => ['Leave encashment exemption used there', false],
        ];
    }

    public function label(): string
    {
        return 'Previous employment upload';
    }

    protected function aliases(): array
    {
        return ['fy' => 'financial_year', 'year' => 'financial_year', 'employer' => 'name', 'employer_name' => 'name', 'previous_employer_name' => 'name', 'employer_tan' => 'tan',
            'from_date' => 'from', 'to_date' => 'to', 'taxable_salary' => 'income', 'salary' => 'income', 'tds_deducted' => 'tds', 'income_tax' => 'tds'];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        [$year, $yearError] = $this->yearOf($r);
        if ($yearError !== null) {
            $errors[] = $yearError;
        }
        if (mb_strlen(trim((string) ($r['name'] ?? ''))) > 150) {
            $errors[] = 'Previous employer can be at most 150 characters.';
        }
        if (isset($r['tan']) && ! preg_match('/^[A-Z]{4}[0-9]{5}[A-Z]$/', strtoupper($r['tan']))) {
            $errors[] = 'Their TAN must look like ABCD12345E.';
        }
        foreach (self::AMOUNTS as $field => $label) {
            if (isset($r[$field]) && (($v = $this->money($r[$field])) === null || $v < 0)) {
                $errors[] = "{$label} must be a number, zero or more.";
            }
        }
        $from = isset($r['from']) ? $this->date($r['from']) : null;
        $to = isset($r['to']) ? $this->date($r['to']) : null;
        if ((isset($r['from']) && $from === null) || (isset($r['to']) && $to === null)) {
            $errors[] = 'Worked there from and until must be dates (YYYY-MM-DD).';
        } elseif ($from !== null && $to !== null && $from > $to) {
            $errors[] = 'Worked there until cannot be before worked there from.';
        } elseif ($year !== null) {
            $fy = app(FinancialYear::class);
            $start = Carbon::parse(substr($year, 0, 4).'-'.str_pad((string) config('peopleos.compliance.financial_year_start_month', 4), 2, '0', STR_PAD_LEFT).'-01');
            foreach (array_filter([$from, $to]) as $date) {
                if ($fy->label($date) !== $year) {
                    $errors[] = "The dates there must fall in {$year} (".$start->toDateString().' onwards).';
                    break;
                }
            }
        }
        if ($errors !== []) {
            return ['action' => 'error', 'errors' => $errors, 'employee_id' => $employeeId];
        }
        $employee = Employee::query()->findOrFail($employeeId);
        if (($refusal = $this->refusal($employee, $year, $import)) !== null) {
            return ['action' => 'error', 'errors' => [$refusal], 'employee_id' => $employeeId];
        }
        if (($duplicate = $this->seenBefore("{$employeeId}|{$year}", $context, $row->row_number, 'employee and year')) !== null) {
            return ['action' => 'error', 'errors' => [$duplicate], 'employee_id' => $employeeId];
        }

        $current = $this->declarations()->currentInput($employee, $year);
        $onFile = $current['previous_employer_income'] > 0 || $current['previous_employer_tds'] > 0 || ! empty($current['previous_employer']);
        if (! $onFile) {
            return ['action' => 'create', 'employee_id' => $employeeId];
        }
        [$income, $tds, $details] = $this->previous($r);
        $stored = collect((array) $current['previous_employer'])->map(fn ($v) => is_numeric($v) ? (float) $v : $v)->sortKeys()->all();
        $same = round($current['previous_employer_income'], 2) === $income && round($current['previous_employer_tds'], 2) === $tds
            && collect($details)->map(fn ($v) => is_numeric($v) ? (float) $v : $v)->sortKeys()->all() == $stored;

        return $same
            ? ['action' => 'skip', 'employee_id' => $employeeId, 'match' => ['reason' => 'The previous employer is already on the declaration with these details.']]
            : ['action' => 'update', 'employee_id' => $employeeId, 'match' => ['reason' => 'Replaces the previous employer on the declaration.']];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $employee = Employee::query()->findOrFail($row->employee_id);
        [$year] = $this->yearOf($r);
        [$income, $tds, $details] = $this->previous($r);
        $input = $this->declarations()->currentInput($employee, $year);
        $input['previous_employer_income'] = $income;
        $input['previous_employer_tds'] = $tds;
        $input['previous_employer'] = $details;
        $declaration = $this->save($user, $employee, $year, $input);

        return 'Previous employer saved on the '.$declaration->financial_year.' declaration ('.$declaration->status.')';
    }

    /** @return array{0: float, 1: float, 2: array<string, mixed>} taxable salary, tax and the stored details */
    private function previous(array $r): array
    {
        $details = array_filter([
            'name' => trim((string) $r['name']), 'tan' => isset($r['tan']) ? strtoupper($r['tan']) : null,
            'from' => isset($r['from']) ? $this->date($r['from']) : null, 'to' => isset($r['to']) ? $this->date($r['to']) : null,
            'pf' => isset($r['pf']) ? $this->money($r['pf']) : null, 'pt' => isset($r['pt']) ? $this->money($r['pt']) : null,
            'gratuity_exempt' => isset($r['gratuity_exempt']) ? $this->money($r['gratuity_exempt']) : null,
            'leave_encashment_exempt' => isset($r['leave_encashment_exempt']) ? $this->money($r['leave_encashment_exempt']) : null,
        ], fn ($v) => $v !== null && $v !== '');

        return [(float) $this->money($r['income']), (float) $this->money($r['tds']), $details];
    }

    public function format(): array
    {
        [$code] = $this->sampleCodes(1);
        $year = $this->thisYear();
        $start = substr($year, 0, 4);

        return [
            'columns' => [
                ['Employee code', 'Yes', "The employee's code.", $code],
                ['Financial year', 'Yes', 'April to March, as 2026-27: last, this or next year.', $year],
                ['Previous employer', 'Yes', 'The employer\'s name.', 'Acme Software Pvt Ltd'],
                ['Their TAN', 'No', 'ABCD12345E, from their Form 16 / 12B.', 'BLRA12345B'],
                ['Worked there from', 'No', 'YYYY-MM-DD, inside the financial year.', "{$start}-04-01"],
                ['Worked there until', 'No', 'YYYY-MM-DD, inside the financial year.', "{$start}-06-30"],
                ['Taxable salary there', 'Yes', 'Taxable salary paid there this year (Form 16 / 12B).', '300000'],
                ['Income tax deducted there', 'Yes', 'TDS they deducted.', '12000'],
                ['PF deducted there', 'No', 'Counts under 80C (old regime).', '10800'],
                ['Professional tax deducted there', 'No', 'Counts like payroll PT (old regime).', '600'],
                ['Gratuity exemption used there', 'No', 'Tax-free gratuity already given there, if any.', ''],
                ['Leave encashment exemption used there', 'No', 'Tax-free leave encashment already given there, if any.', ''],
            ],
            'notes' => [
                'One row per employee and year: it replaces the previous employer on the declaration (choose Update existing records or Insert and update). The same details are skipped.',
                'The declaration screen\'s rules apply (payroll in your scope, payroll may edit submitted declarations); a verified declaration is never changed.',
                'The details stay encrypted on the declaration and print on Form 12BB.',
            ],
        ];
    }

    protected function examples(): array
    {
        [$first, $second] = $this->sampleCodes(2);
        $year = $this->thisYear();
        $start = substr($year, 0, 4);

        return [
            [$first, $year, 'Acme Software Pvt Ltd', 'BLRA12345B', "{$start}-04-01", "{$start}-06-30", '300000', '12000', '10800', '600', '', ''],
            [$second, $year, 'Bright Retail LLP', 'MUMB54321C', "{$start}-04-01", "{$start}-07-31", '220000', '0', '7200', '800', '', ''],
        ];
    }
}
