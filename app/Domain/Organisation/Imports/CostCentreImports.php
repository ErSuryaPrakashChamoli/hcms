<?php

namespace App\Domain\Organisation\Imports;

use App\Domain\Employment\Imports\MasterImports;
use App\Domain\Organisation\Imports\Concerns\ReadsMasterRows;
use App\Domain\Organisation\Imports\Concerns\RequiresUpdatePermission;
use App\Domain\Organisation\Imports\Concerns\SetsApplicability;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\CostCentre;
use App\Support\Imports\ClearValue;
use Illuminate\Database\Eloquent\Model;

/** Master uploads (Core → Cost centres): cost centres by code, each optionally in a company. Departments refer to them. */
final class CostCentreImports extends MasterImports
{
    use ReadsMasterRows, RequiresUpdatePermission, SetsApplicability;

    public const TYPE = 'cost_centres';

    public static function fields(): array
    {
        return ['code' => ['Code', true], 'name' => ['Name', true], 'company_code' => ['Company code', false], 'description' => ['Description', false], 'status' => ['Status', false]] + self::applicabilityFields();
    }

    public function label(): string
    {
        return 'Cost centres upload';
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
        return CostCentre::class;
    }

    protected function aliases(): array
    {
        return ['cost_centre_code' => 'code', 'cost_center_code' => 'code', 'cost_centre' => 'name', 'cost_center' => 'name', 'company' => 'company_code'];
    }

    protected function attributes(array $r, array &$context, ?Model $existing, array &$errors): array
    {
        return array_filter([
            'name' => $this->text($r['name'] ?? null, 255, 'Name', $errors),
            'company_id' => $this->lookup($context, Company::class, $r['company_code'] ?? null, 'Company code', $errors),
            'description' => $this->text($r['description'] ?? null, 1000, 'Description', $errors),
            'status' => $this->status($r['status'] ?? null, $errors),
        ], fn ($v) => $v !== null);
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Code', 'Yes', 'The cost centre code (up to 32 characters). A code on file updates that cost centre.', 'CC-100'],
                ['Name', 'Yes', 'The cost centre name.', 'Product engineering'],
                ['Company code', 'No', 'Code of the company it belongs to (Organisation → Companies). Blank: shared by every company. '.ClearValue::hint(), 'MKT'],
                ['Description', 'No', 'What it covers.', 'Engineering salaries and tools'],
                ['Status', 'No', 'active or inactive. Blank for a new one is active.', 'active'],
                ...self::applicabilityColumns(),
            ],
            'notes' => [
                'Rows are matched on Code (any case). A new code adds a cost centre; a code on file updates it; a row whose values are already on file is skipped.',
                'Blank cells keep what is on file.',
            ],
        ];
    }

    protected function examples(): array
    {
        $company = Company::query()->orderBy('id')->value('code') ?? 'MKT';

        return [['CC-100', 'Product engineering', $company, 'Engineering salaries and tools', 'active'], ['CC-200', 'Corporate', '', 'Shared services', 'active']];
    }
}
