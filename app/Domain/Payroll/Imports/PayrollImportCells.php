<?php

namespace App\Domain\Payroll\Imports;

use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;

/**
 * Data import DI-4: how the payroll uploads read a cell — amounts with thousands separators ("6,00,000"), yes / no,
 * a month (YYYY-MM) — and the example employee codes of a sample file. Shared by the payroll head's RowImports.
 */
trait PayrollImportCells
{
    /** "6,00,000", "1 200.50" and "-50" are numbers; anything else (or blank) is null. */
    protected function money(?string $value): ?float
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $clean = str_replace([',', ' ', "\u{00A0}", '₹'], '', trim($value));

        return is_numeric($clean) ? round((float) $clean, 2) : null;
    }

    /** yes / no (y, n, true, false, 1, 0); null when blank or anything else. */
    protected function yesNo(?string $value): ?bool
    {
        return match (mb_strtolower(trim((string) $value))) {
            'yes', 'y', 'true', '1' => true,
            'no', 'n', 'false', '0' => false,
            default => null,
        };
    }

    /** A month as YYYY-MM (also accepts MM-YYYY and MM/YYYY), or null. */
    protected function month(?string $value): ?string
    {
        $value = trim((string) $value);
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $value)) {
            return $value;
        }
        if (preg_match('/^(0?[1-9]|1[0-2])[-\/](\d{4})$/', $value, $m)) {
            return sprintf('%04d-%02d', (int) $m[2], (int) $m[1]);
        }

        return null;
    }

    /** A financial year label as 2026-27, or null. */
    protected function financialYearLabel(?string $value): ?string
    {
        $value = trim((string) $value);
        if (! preg_match('/^(\d{4})-(\d{2}|\d{4})$/', $value, $m)) {
            return null;
        }
        $end = strlen($m[2]) === 4 ? (int) $m[2] : (int) (substr($m[1], 0, 2).$m[2]);

        return $end === (int) $m[1] + 1 ? sprintf('%04d-%02d', (int) $m[1], $end % 100) : null;
    }

    /** @return list<string> the first employee codes on file (for sample rows), padded with EMP0001 / EMP0002 */
    protected function sampleCodes(int $count = 2): array
    {
        $codes = Employee::query()->orderBy('id')->limit($count)->pluck('employee_code')->map(fn ($c) => (string) $c)->all();
        for ($i = count($codes); $i < $count; $i++) {
            $codes[] = sprintf('EMP%04d', $i + 1);
        }

        return $codes;
    }

    /** Duplicate rows of one file are errors naming the first row; returns the error, or null and remembers the row. */
    protected function seenBefore(string $key, array &$context, int $rowNumber, string $what): ?string
    {
        if (isset($context['seen'][$key])) {
            return "The same {$what} is in row {$context['seen'][$key]}.";
        }
        $context['seen'][$key] = $rowNumber;

        return null;
    }

    /** The uploader of the file (who checks run as), or null. */
    protected function uploader(EmployeeImport $import): ?User
    {
        return User::query()->find($import->uploaded_by);
    }
}
