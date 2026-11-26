<?php

namespace App\Domain\Organisation\Imports;

use App\Domain\Employment\Imports\MasterImports;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Imports\Concerns\ReadsMasterRows;
use App\Domain\Organisation\Imports\Concerns\RequiresUpdatePermission;
use App\Domain\Organisation\Imports\Concerns\SetsApplicability;
use App\Domain\Organisation\Models\BusinessUnit;
use App\Domain\Organisation\Models\Company;
use App\Support\Imports\ReferenceGuide;
use Illuminate\Database\Eloquent\Model;

/** Data import DI-1 (Core → Business units): business units of each company, by name (the code is optional), with the company by name. */
final class BusinessUnitImports extends MasterImports
{
    use ReadsMasterRows, RequiresUpdatePermission, SetsApplicability;

    public const TYPE = 'business_units';

    public static function fields(): array
    {
        return ['code' => ['Code', false], 'name' => ['Name', true], 'company_code' => ['Company', false], 'description' => ['Description', false], 'status' => ['Status', false]] + self::applicabilityFields();
    }

    public function label(): string
    {
        return 'Business units upload';
    }

    protected function updatePermission(): string
    {
        return 'organisation.update';
    }

    protected function module(): string
    {
        return 'organisation';
    }

    protected function model(): string
    {
        return BusinessUnit::class;
    }

    protected function aliases(): array
    {
        return ['business_unit_code' => 'code', 'business_unit_name' => 'name', 'bu_code' => 'code', 'company' => 'company_code'];
    }

    protected function attributes(array $r, array &$context, ?Model $existing, array &$errors): array
    {
        return array_filter([
            'name' => $this->text($r['name'] ?? null, 255, 'Name', $errors),
            'company_id' => $this->lookup($context, Company::class, $r['company_code'] ?? null, 'Company', $errors),
            'description' => $this->text($r['description'] ?? null, 1000, 'Description', $errors),
            'status' => $this->status($r['status'] ?? null, $errors),
        ], fn ($v) => $v !== null);
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Code', 'No', ReferenceGuide::ownCode('business unit'), ''],
                ['Name', 'Yes', 'The business unit name.', 'Retail banking'],
                ['Company', 'No', ReferenceGuide::link('company', 'Organisation → Companies'), 'Acme India'],
                ['Description', 'No', 'What it does.', 'Branches and retail products'],
                ['Status', 'No', 'active or inactive. Blank for a new one is active.', 'active'],
                ...self::applicabilityColumns(),
            ],
            'notes' => [
                ReferenceGuide::OWN_CODE_NOTE,
                ReferenceGuide::NOTE,
                'A row whose values are already on file is skipped.',
                'Blank cells keep what is on file. Changes to an existing business unit follow the Configuration Change Centre when your organisation approves configuration changes.',
            ],
        ];
    }

    protected function examples(): array
    {
        $company = Company::query()->withoutGlobalScope(AccessScope::class)->orderBy('id')->value('name') ?? 'Acme India';

        return [['', 'Retail banking', $company, 'Branches and retail products', 'active'], ['', 'Corporate banking', $company, 'Large clients and treasury', 'active']];
    }
}
