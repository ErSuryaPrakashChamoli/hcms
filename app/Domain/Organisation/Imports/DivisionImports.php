<?php

namespace App\Domain\Organisation\Imports;

use App\Domain\Organisation\Models\Division;

/** Master uploads (Core → Divisions upload): divisions by name (the code is optional), each optionally in a company. */
final class DivisionImports extends SimpleMasterImports
{
    public const TYPE = 'divisions';

    protected static function companyOwned(): bool
    {
        return true;
    }

    protected static function noun(): string
    {
        return 'division';
    }

    public function label(): string
    {
        return 'Divisions upload';
    }

    protected function updatePermission(): string
    {
        return 'organisation.update';
    }

    protected function model(): string
    {
        return Division::class;
    }

    protected function exampleValues(): array
    {
        return [['Retail', self::firstCompany(), 'Retail banking division'], ['Corporate', '', 'Corporate functions']];
    }
}
