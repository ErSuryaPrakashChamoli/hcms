<?php

namespace App\Domain\Organisation\Imports;

use App\Domain\Organisation\Models\EmploymentType;

/** Master uploads (Core → Employment types upload): employment types by name (the code is optional), shared or limited to some companies. */
final class EmploymentTypeImports extends SimpleMasterImports
{
    public const TYPE = 'employment_types';

    protected static function companyOwned(): bool
    {
        return false;
    }

    protected static function noun(): string
    {
        return 'employment type';
    }

    public function label(): string
    {
        return 'Employment types upload';
    }

    protected function updatePermission(): string
    {
        return 'people_setup.update';
    }

    protected function model(): string
    {
        return EmploymentType::class;
    }

    protected function exampleValues(): array
    {
        return [['Permanent', 'On the payroll with no end date'], ['Fixed-term contract', 'On the payroll until a set date']];
    }
}
