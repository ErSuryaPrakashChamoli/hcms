<?php

namespace App\Domain\Organisation\Imports;

use App\Domain\Employment\Imports\MasterImports;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Imports\Concerns\ReadsMasterRows;
use App\Domain\Organisation\Imports\Concerns\RequiresUpdatePermission;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\CostCentre;
use App\Domain\Organisation\Models\Department;
use Illuminate\Database\Eloquent\Model;

/**
 * Data import DI-1 (Core → Departments): departments by code, with company and cost centre. Never changes where a
 * department sits (its parent department: the Sub departments upload does that).
 */
class DepartmentImports extends MasterImports
{
    use ReadsMasterRows, RequiresUpdatePermission;

    public const TYPE = 'departments';

    public static function fields(): array
    {
        return ['code' => ['Code', true], 'name' => ['Name', true], 'company_code' => ['Company code', false], 'cost_centre_code' => ['Cost centre code', false],
            'description' => ['Description', false], 'status' => ['Status', false]];
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
            'company_id' => $this->lookup($context, Company::class, $r['company_code'] ?? null, 'Company code', $errors),
            'cost_centre_id' => $this->lookup($context, CostCentre::class, $r['cost_centre_code'] ?? null, 'Cost centre code', $errors),
            'description' => $this->text($r['description'] ?? null, 1000, 'Description', $errors),
            'status' => $this->status($r['status'] ?? null, $errors),
        ], fn ($v) => $v !== null);
        $parentId = $existing?->getAttributes()['parent_department_id'] ?? null;
        if ($existing !== null && $parentId !== null && isset($attributes['company_id']) && (int) $attributes['company_id'] !== (int) $existing->company_id
            && (int) Department::query()->withoutGlobalScope(AccessScope::class)->whereKey($parentId)->value('company_id') !== (int) $attributes['company_id']) {
            $errors[] = "'{$existing->code}' is a sub department: it stays in its parent department's company.";
        }
        if ($existing !== null && isset($attributes['company_id']) && (int) $attributes['company_id'] !== (int) $existing->company_id
            && Department::query()->withoutGlobalScope(AccessScope::class)->where('parent_department_id', $existing->getKey())->exists()) {
            $errors[] = "'{$existing->code}' has sub departments in its company: move them to the other company first.";
        }

        return $attributes;
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Code', 'Yes', 'The department code (up to 32 characters). A code on file updates that department.', 'ENG'],
                ['Name', 'Yes', 'The department name.', 'Engineering'],
                ['Company code', 'No', 'Code of the company it belongs to (Organisation → Companies).', 'ACME'],
                ['Cost centre code', 'No', 'Code of its cost centre (Organisation → Cost centres).', 'CC-100'],
                ['Description', 'No', 'What it does.', 'Product engineering'],
                ['Status', 'No', 'active or inactive. Blank for a new one is active.', 'active'],
            ],
            'notes' => [
                'Rows are matched on Code (any case). A new code adds a department; a code on file updates it; a row whose values are already on file is skipped.',
                'Blank cells keep what is on file. A department\'s parent (for a sub department) is never changed here: use the Sub departments upload.',
                'Changes to an existing department follow the Configuration Change Centre when your organisation approves configuration changes.',
            ],
        ];
    }

    protected function examples(): array
    {
        $company = Company::query()->withoutGlobalScope(AccessScope::class)->orderBy('id')->value('code') ?? '';
        $costCentre = CostCentre::query()->withoutGlobalScope(AccessScope::class)->orderBy('id')->value('code') ?? '';

        return [['ENG', 'Engineering', $company, $costCentre, 'Product engineering', 'active'], ['FIN', 'Finance', $company, $costCentre, 'Accounts and treasury', 'active']];
    }
}
