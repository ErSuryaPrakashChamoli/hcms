<?php

namespace App\Domain\Organisation\Imports;

use App\Domain\Employment\Imports\MasterImports;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Imports\Concerns\ReadsMasterRows;
use App\Domain\Organisation\Imports\Concerns\RequiresUpdatePermission;
use App\Domain\Organisation\Imports\Concerns\SetsApplicability;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\CostCentre;
use App\Domain\Organisation\Models\Department;
use App\Support\Imports\ReferenceGuide;
use Illuminate\Database\Eloquent\Model;

/**
 * Data import DI-1 (Core → Departments): departments with company and cost centre, all by name (names first: the code
 * may be left blank). Never changes where a department sits (its parent department: the Sub departments upload does that).
 */
class DepartmentImports extends MasterImports
{
    use ReadsMasterRows, RequiresUpdatePermission, SetsApplicability;

    public const TYPE = 'departments';

    public static function fields(): array
    {
        return ['code' => ['Code', false], 'name' => ['Name', true], 'company_code' => ['Company', false], 'cost_centre_code' => ['Cost centre', false],
            'description' => ['Description', false], 'status' => ['Status', false]] + self::applicabilityFields();
    }

    public function label(): string
    {
        return 'Departments upload';
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
        return Department::class;
    }

    protected function aliases(): array
    {
        return ['department_code' => 'code', 'department_name' => 'name', 'company' => 'company_code', 'cost_centre' => 'cost_centre_code', 'cost_center_code' => 'cost_centre_code'];
    }

    protected function attributes(array $r, array &$context, ?Model $existing, array &$errors): array
    {
        $attributes = array_filter([
            'name' => $this->text($r['name'] ?? null, 255, 'Name', $errors),
            'company_id' => $this->lookup($context, Company::class, $r['company_code'] ?? null, 'Company', $errors),
            'cost_centre_id' => $this->lookup($context, CostCentre::class, $r['cost_centre_code'] ?? null, 'Cost centre', $errors),
            'description' => $this->text($r['description'] ?? null, 1000, 'Description', $errors),
            'status' => $this->status($r['status'] ?? null, $errors),
        ], fn ($v) => $v !== null);
        $parentId = $existing?->getAttributes()['parent_department_id'] ?? null;
        if ($existing !== null && $parentId !== null && isset($attributes['company_id']) && (int) $attributes['company_id'] !== (int) $existing->company_id
            && (int) Department::query()->withoutGlobalScope(AccessScope::class)->whereKey($parentId)->value('company_id') !== (int) $attributes['company_id']) {
            $errors[] = "'{$existing->name}' is a sub department: it stays in its parent department's company.";
        }
        if ($existing !== null && isset($attributes['company_id']) && (int) $attributes['company_id'] !== (int) $existing->company_id
            && Department::query()->withoutGlobalScope(AccessScope::class)->where('parent_department_id', $existing->getKey())->exists()) {
            $errors[] = "'{$existing->name}' has sub departments in its company: move them to the other company first.";
        }

        return $attributes;
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Code', 'No', ReferenceGuide::ownCode('department'), ''],
                ['Name', 'Yes', 'The department name.', 'Engineering'],
                ['Company', 'No', ReferenceGuide::link('company', 'Organisation → Companies', 'Blank: every company may use it.'), 'Acme India'],
                ['Cost centre', 'No', ReferenceGuide::link('cost centre', 'Organisation → Cost centres'), 'Head office'],
                ['Description', 'No', 'What it does.', 'Product engineering'],
                ['Status', 'No', 'active or inactive. Blank for a new one is active.', 'active'],
                ...self::applicabilityColumns(),
            ],
            'notes' => [
                ReferenceGuide::OWN_CODE_NOTE,
                ReferenceGuide::NOTE,
                'A row whose values are already on file is skipped.',
                'Blank cells keep what is on file. A department\'s parent (for a sub department) is never changed here: use the Sub departments upload.',
                'Changes to an existing department follow the Configuration Change Centre when your organisation approves configuration changes.',
            ],
        ];
    }

    protected function examples(): array
    {
        $company = Company::query()->withoutGlobalScope(AccessScope::class)->orderBy('id')->value('name') ?? '';
        $costCentre = CostCentre::query()->withoutGlobalScope(AccessScope::class)->orderBy('id')->value('name') ?? '';

        return [['', 'Engineering', $company, $costCentre, 'Product engineering', 'active'], ['', 'Finance', $company, $costCentre, 'Accounts and treasury', 'active']];
    }
}
