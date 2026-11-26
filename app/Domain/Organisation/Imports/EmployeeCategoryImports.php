<?php

namespace App\Domain\Organisation\Imports;

use App\Domain\Organisation\Models\EmployeeCategory;

/** Master uploads (Core → Employee categories upload): employee categories by name (the code is optional), shared or limited to some companies. */
final class EmployeeCategoryImports extends SimpleMasterImports
{
    public const TYPE = 'employee_categories';

    protected static function companyOwned(): bool
    {
        return false;
    }

    protected static function noun(): string
    {
        return 'employee category';
    }

    public function label(): string
    {
        return 'Employee categories upload';
    }

    protected function updatePermission(): string
    {
        return 'people_setup.update';
    }

    protected function model(): string
    {
        return EmployeeCategory::class;
    }

    protected function exampleValues(): array
    {
        return [['Staff', 'Office staff'], ['Worker', 'Shop-floor workers']];
    }
}
