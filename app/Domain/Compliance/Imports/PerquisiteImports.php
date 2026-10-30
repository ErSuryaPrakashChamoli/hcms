<?php

namespace App\Domain\Compliance\Imports;

use App\Domain\Compliance\Models\TaxIncomeEntry;
use App\Domain\Compliance\Services\FinancialYear;
use App\Domain\Compliance\Services\TaxIncomeEntries;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;

/** HROne parity HP-3 (PY-30): perquisites for the year from a file, each recorded for TDS. */
final class PerquisiteImports extends RowImports
{
    public const TYPE = 'perquisites';

    public static function fields(): array
    {
        return ['employee_code' => ['Employee code', true], 'financial_year' => ['Financial year', true], 'type' => ['Perquisite type', true], 'description' => ['Description', true],
            'value' => ['Value for the year', true], 'recovered' => ['Recovered from the employee', false], 'reference' => ['Reference', false]];
    }

    public function label(): string
    {
        return 'Perquisite upload';
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
        return ['fy' => 'financial_year', 'perquisite' => 'type', 'perquisite_type' => 'type', 'amount' => 'value', 'annual_value' => 'value'];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        if (! preg_match('/^\d{4}-\d{2}$/', (string) ($r['financial_year'] ?? ''))) {
            $errors[] = 'Financial year must look like 2026-27.';
        }
        if ($this->type($r) === null) {
            $errors[] = "Perquisite type '".($r['type'] ?? '')."' is not one of: ".implode(', ', array_keys((array) config('peopleos.compliance.perquisite_types'))).'.';
        }
        $value = $r['value'] ?? null;
        $recovered = $r['recovered'] ?? '0';
        if (! is_numeric($value) || (float) $value <= 0) {
            $errors[] = 'Value must be a number above zero.';
        } elseif (! is_numeric($recovered) || (float) $recovered < 0 || (float) $recovered > (float) $value) {
            $errors[] = 'Recovered must be between zero and the value.';
        }

        return $errors !== [] ? ['action' => 'error', 'errors' => $errors, 'employee_id' => $employeeId] : ['action' => 'create', 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $entry = app(TaxIncomeEntries::class)->recordPerquisite(Employee::query()->findOrFail($row->employee_id), $r['financial_year'], $this->type($r), $r['description'], (float) $r['value'], (float) ($r['recovered'] ?? 0), $user, $r['reference'] ?? $this->label()." #{$import->getKey()}");

        return 'Taxable value '.number_format((float) $entry->taxable_amount, 2);
    }

    /** The type key, given as the key or its label. */
    private function type(array $r): ?string
    {
        $given = mb_strtolower(trim((string) ($r['type'] ?? '')));
        foreach ((array) config('peopleos.compliance.perquisite_types') as $key => $label) {
            if ($given === $key || $given === mb_strtolower($label)) {
                return $key;
            }
        }

        return null;
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Employee code', 'Yes', "The employee's code.", 'EMP0001'],
                ['Financial year', 'Yes', 'April to March, as 2026-27.', app(FinancialYear::class)->label(now())],
                ['Perquisite type', 'Yes', 'A type key or its name.', 'car'],
                ['Description', 'Yes', 'What the perquisite is.', 'Company car with driver'],
                ['Value for the year', 'Yes', 'The perquisite value for the whole year.', '43200'],
                ['Recovered from the employee', 'No', 'What the employee pays back; the taxable value is the difference.', '0'],
                ['Reference', 'No', 'Your reference.', ''],
            ],
            'codes' => (array) config('peopleos.compliance.perquisite_types'),
            'codes_title' => 'Perquisite types',
            'notes' => ['Each row adds a perquisite; cancel a wrong one on Payroll → Perquisites and other income.', 'TDS counts the taxable value from the next calculation.'],
        ];
    }

    protected function examples(): array
    {
        $code = Employee::query()->orderBy('id')->value('employee_code') ?? 'EMP0001';

        return [[$code, app(FinancialYear::class)->label(now()), 'car', 'Company car with driver', '43200', '0', '']];
    }
}
