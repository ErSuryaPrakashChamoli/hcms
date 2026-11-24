<?php

namespace App\Domain\Organisation\Imports;

use App\Domain\Organisation\Models\ProfitCentre;

/** Master uploads (Core → Profit centres upload): profit centres by code, each optionally in a company. */
final class ProfitCentreImports extends SimpleMasterImports
{
    public const TYPE = 'profit_centres';

    protected static function companyOwned(): bool
    {
        return true;
    }

    protected static function noun(): string
    {
        return 'profit centre';
    }

    public function label(): string
    {
        return 'Profit centres upload';
    }

    protected function updatePermission(): string
    {
        return 'organisation.update';
    }

    protected function model(): string
    {
        return ProfitCentre::class;
    }

    protected function exampleValues(): array
    {
        return [['PC-100', 'Consulting', self::firstCompany(), 'Consulting revenue'], ['PC-200', 'Products', '', 'Product revenue']];
    }
}
