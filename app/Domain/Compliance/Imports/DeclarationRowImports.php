<?php

namespace App\Domain\Compliance\Imports;

use App\Domain\Compliance\Models\EmployeeTaxDeclaration;
use App\Domain\Compliance\Services\InvestmentDeclarations;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Imports\PayrollImportCells;

/**
 * Data import DI-4: the uploads that write an employee's tax declaration (Investments, HRA declaration, Previous
 * employment). Each row changes one part of the declaration and saves it through InvestmentDeclarations::save() with
 * everything else as it is (currentInput()), so the screen's rules apply: payroll in scope, the declaration window,
 * "payroll may edit submitted declarations", the landlord rules, and items whose proof payroll verified are never
 * changed. A declaration payroll has verified is not changed by an upload. A new declaration is saved as submitted (payroll
 * entered it); an existing one keeps its status.
 */
abstract class DeclarationRowImports extends RowImports
{
    use PayrollImportCells;

    protected function module(): string
    {
        return 'compliance';
    }

    protected function entityType(): string
    {
        return EmployeeTaxDeclaration::class;
    }

    protected function declarations(): InvestmentDeclarations
    {
        return app(InvestmentDeclarations::class);
    }

    /** The year the row names when it is one the declaration screen offers, or the error. @return array{0: ?string, 1: ?string} */
    protected function yearOf(array $r): array
    {
        $year = $this->financialYearLabel($r['financial_year'] ?? null);
        if ($year === null) {
            return [null, 'Financial year must look like '.array_key_first(array_slice($this->declarations()->years(), 1, 1, true)).'.'];
        }
        if (! array_key_exists($year, $this->declarations()->years())) {
            return [null, 'Financial year must be one of '.implode(', ', array_keys($this->declarations()->years())).'.'];
        }

        return [$year, null];
    }

    /** This financial year's label (the middle of the years the declaration screen offers), for the format and samples. */
    protected function thisYear(): string
    {
        return (string) array_key_first(array_slice($this->declarations()->years(), 1, 1, true));
    }

    /** Why the uploader may not change this employee's declaration for the year, or null. */
    protected function refusal(Employee $employee, string $year, EmployeeImport $import): ?string
    {
        $uploader = $this->uploader($import);
        if ($uploader === null) {
            return 'The uploader of this file no longer exists.';
        }
        $declaration = $this->declarations()->declaration($employee, $year);
        if ($declaration?->status === 'verified') {
            return "Payroll has verified this employee's {$year} declaration; change it on Tax declarations.";
        }
        if (! $this->declarations()->canEdit($uploader, $employee, $declaration)) {
            return "You cannot change this employee's {$year} declaration (outside your scope, or payroll may not edit submitted declarations).";
        }

        return null;
    }

    /** The regime a row asks for (old / new), the current one when blank; null when not valid. */
    protected function regime(array $r, array $current): ?string
    {
        if (! isset($r['regime'])) {
            return $current['regime'];
        }
        $given = mb_strtolower(trim($r['regime']));

        return array_key_exists($given, (array) config('peopleos.compliance.tax_regimes')) ? $given : null;
    }

    /** Save the changed input; a new declaration is submitted, an existing one keeps its status. */
    protected function save(User $user, Employee $employee, string $year, array $input): EmployeeTaxDeclaration
    {
        $existing = $this->declarations()->declaration($employee, $year);

        return $this->declarations()->save($user, $employee, $year, $input, $existing === null || $existing->status === 'submitted');
    }
}
