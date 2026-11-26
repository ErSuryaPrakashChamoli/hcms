<?php

namespace App\Domain\Organisation\Imports;

use App\Domain\Organisation\Models\Team;

/** Master uploads (Core → Teams upload): teams by name (the code is optional), each optionally in a company. */
final class TeamImports extends SimpleMasterImports
{
    public const TYPE = 'teams';

    protected static function companyOwned(): bool
    {
        return true;
    }

    protected static function noun(): string
    {
        return 'team';
    }

    public function label(): string
    {
        return 'Teams upload';
    }

    protected function updatePermission(): string
    {
        return 'organisation.update';
    }

    protected function model(): string
    {
        return Team::class;
    }

    protected function exampleValues(): array
    {
        return [['Payroll operations', self::firstCompany(), 'Runs payroll each month'], ['Talent acquisition', '', 'Hiring']];
    }
}
