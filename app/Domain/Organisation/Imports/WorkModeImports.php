<?php

namespace App\Domain\Organisation\Imports;

use App\Domain\Organisation\Models\WorkMode;

/** Master uploads (Core → Work modes upload): work modes by code, shared or limited to some companies. */
final class WorkModeImports extends SimpleMasterImports
{
    public const TYPE = 'work_modes';

    protected static function companyOwned(): bool
    {
        return false;
    }

    protected static function noun(): string
    {
        return 'work mode';
    }

    public function label(): string
    {
        return 'Work modes upload';
    }

    protected function updatePermission(): string
    {
        return 'people_setup.update';
    }

    protected function model(): string
    {
        return WorkMode::class;
    }

    protected function exampleValues(): array
    {
        return [['OFFICE', 'Office', 'Works from an office'], ['HYBRID', 'Hybrid', 'Some days at home']];
    }
}
