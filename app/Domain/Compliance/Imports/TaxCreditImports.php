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

/**
 * Data import DI-4 (Payroll → 12BAA declaration): tax deducted (TDS) or collected (TCS) at source elsewhere in the year
 * that employees declare so salary TDS can take it into account, one certificate line per row, recorded through
 * TaxIncomeEntries (kind tax_credit). TDS counts it as tax already deducted (unless the TDS rule in force carries
 * `other_tds_tcs_credit: false`); the income itself is declared as Other income on the investment declaration. Rows only add.
 */
final class TaxCreditImports extends RowImports
{
    use PayrollImportCells;

    public const TYPE = 'tax_12baa';

    public static function fields(): array
    {
        return [
            'employee_code' => ['Employee code', true], 'credit_type' => ['TDS or TCS', true], 'section' => ['Provision', true], 'deducted_on' => ['Deducted or collected on', true],
            'amount_paid' => ['Amount paid or received', false], 'tax' => ['Tax deducted or collected', true], 'deductor_name' => ['Deductor or collector', false],
            'deductor_tan' => ['Deductor TAN', false], 'reference' => ['Reference', false],
        ];
    }

    public static function intents(): bool
    {
        return false; // each row declares one more certificate line
    }

    public function label(): string
    {
        return '12BAA declaration upload';
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
        return ['type' => 'credit_type', 'tds_tcs' => 'credit_type', 'section_code' => 'section', 'date' => 'deducted_on', 'amount' => 'amount_paid',
            'tds' => 'tax', 'tcs' => 'tax', 'tax_amount' => 'tax', 'deductor' => 'deductor_name', 'collector' => 'deductor_name', 'tan' => 'deductor_tan'];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        $type = $this->creditType($r['credit_type'] ?? null);
        if ($type === null) {
            $errors[] = 'TDS or TCS must be TDS (tax deducted) or TCS (tax collected).';
        }
        $section = trim((string) ($r['section'] ?? ''));
        if ($section === '' || mb_strlen($section) > 24) {
            $errors[] = 'Provision is required (up to 24 characters), as printed on the certificate.';
        }
        $on = $this->date($r['deducted_on'] ?? null);
        if ($on === null) {
            $errors[] = 'Deducted or collected on must be a date (YYYY-MM-DD).';
        } elseif ($on > now()->toDateString()) {
            $errors[] = 'Deducted or collected on cannot be in the future.';
        }
        $tax = $this->money($r['tax'] ?? null);
        if ($tax === null || $tax <= 0) {
            $errors[] = 'Tax deducted or collected must be a number above zero.';
        }
        $paid = isset($r['amount_paid']) ? $this->money($r['amount_paid']) : 0.0;
        if ($paid === null || $paid < 0) {
            $errors[] = 'Amount paid or received must be a number, zero or more.';
        }
        if (isset($r['deductor_tan']) && ! preg_match('/^[A-Z]{4}[0-9]{5}[A-Z]$/', strtoupper($r['deductor_tan']))) {
            $errors[] = 'Deductor TAN must look like ABCD12345E.';
        }
        if ($errors !== []) {
            return ['action' => 'error', 'errors' => $errors, 'employee_id' => $employeeId];
        }

        $tan = isset($r['deductor_tan']) ? strtoupper($r['deductor_tan']) : null;
        $key = implode('|', [$employeeId, $type, mb_strtolower($section), $on, $tax, $tan]);
        if (($duplicate = $this->seenBefore($key, $context, $row->row_number, 'certificate line')) !== null) {
            return ['action' => 'error', 'errors' => [$duplicate], 'employee_id' => $employeeId];
        }
        $onFile = TaxIncomeEntry::query()->active()->where('employee_id', $employeeId)->where('kind', 'tax_credit')->where('credit_type', $type)
            ->where('section', $section)->whereDate('paid_on', $on)->where('tds_deducted', $tax)->where('deductor_tan', $tan)->exists();

        return $onFile
            ? ['action' => 'skip', 'employee_id' => $employeeId, 'match' => ['reason' => 'This certificate line is already declared.']]
            : ['action' => 'create', 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $entry = app(TaxIncomeEntries::class)->recordTaxCredit(Employee::query()->findOrFail($row->employee_id), $this->creditType($r['credit_type']), $this->date($r['deducted_on']),
            $r['section'], (float) ($this->money($r['amount_paid'] ?? null) ?? 0), (float) $this->money($r['tax']), $r['deductor_name'] ?? null, $r['deductor_tan'] ?? null,
            $user, $r['reference'] ?? $this->label()." #{$import->getKey()}");

        return strtoupper((string) $entry->credit_type).' of '.number_format((float) $entry->tds_deducted, 2)." declared for {$entry->financial_year}";
    }

    private function creditType(?string $value): ?string
    {
        return match (mb_strtolower(trim((string) $value))) {
            'tds', 'deducted', 'tax deducted at source' => 'tds',
            'tcs', 'collected', 'tax collected at source' => 'tcs',
            default => null,
        };
    }

    public function format(): array
    {
        [$code] = $this->sampleCodes(1);

        return [
            'columns' => [
                ['Employee code', 'Yes', "The employee's code.", $code],
                ['TDS or TCS', 'Yes', 'TDS (tax deducted at source) or TCS (tax collected at source).', 'TDS'],
                ['Provision', 'Yes', 'The provision the tax was deducted or collected under, as printed on the TDS or TCS certificate.', '393'],
                ['Deducted or collected on', 'Yes', 'YYYY-MM-DD; it decides the financial year the credit counts in.', now()->subMonth()->startOfMonth()->toDateString()],
                ['Amount paid or received', 'No', 'The payment the tax was deducted from, or the amount the tax was collected on.', '50000'],
                ['Tax deducted or collected', 'Yes', 'The tax on the certificate.', '5000'],
                ['Deductor or collector', 'No', 'Who deducted or collected it.', 'State Bank of India'],
                ['Deductor TAN', 'No', 'Their TAN, ABCD12345E.', 'MUMS12345A'],
                ['Reference', 'No', 'Your reference, e.g. the certificate number.', ''],
            ],
            'notes' => [
                'Each row declares one certificate line (Form 12BAA); the same line already declared is skipped. Cancel a wrong one on Payroll → Perquisites and other income.',
                'Salary TDS counts the tax as already deducted from the next calculation (the tax computation shows it), unless the TDS rule in force turns the credit off.',
                'The income the tax was deducted from is declared as Other income on the investment declaration (Investments upload, item OTHER_INCOME.other).',
            ],
        ];
    }

    protected function examples(): array
    {
        [$first, $second] = $this->sampleCodes(2);
        $month = now()->subMonth()->startOfMonth();

        return [
            [$first, 'TDS', '393', $month->toDateString(), '50000', '5000', 'State Bank of India', 'MUMS12345A', 'FD interest certificate'],
            [$second, 'TCS', '394', $month->copy()->addDays(9)->toDateString(), '800000', '40000', 'Global Travels Ltd', 'DELG54321B', 'Overseas tour package'],
        ];
    }
}
