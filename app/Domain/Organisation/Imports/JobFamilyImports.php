<?php

namespace App\Domain\Organisation\Imports;

use App\Domain\Organisation\Models\JobFamily;

/** Master uploads (Core → Job families upload): job familys by code, shared or limited to some companies. */
final class JobFamilyImports extends SimpleMasterImports
{
    public const TYPE = 'job_families';

    protected static function companyOwned(): bool
    {
        return false;
    }

    protected static function noun(): string
    {
        return 'job family';
    }

    public function label(): string
    {
        return 'Job families upload';
    }

    protected function updatePermission(): string
    {
        return 'people_setup.update';
    }

    protected function model(): string
    {
        return JobFamily::class;
    }

    protected function exampleValues(): array
    {
        return [['TECH', 'Technology', 'Engineering and IT roles'], ['FIN', 'Finance', 'Accounting and treasury roles']];
    }
}
