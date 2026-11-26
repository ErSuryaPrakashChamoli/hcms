<?php

namespace App\Domain\Organisation\Imports;

use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\CostCentre;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Services\DepartmentHierarchy;
use App\Support\Imports\ReferenceGuide;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Data import DI-1 (Core → Sub departments): departments inside a department, by name (the code is optional), with the
 * parent department by name (on file, or an earlier row of the file). A new sub department takes its parent's company;
 * a department on file moves under the named parent, which must be in its company. A department never sits under itself or one of its own sub departments (DepartmentHierarchy,
 * as on the department screen), and the organisation designer follows the new parent.
 */
final class SubDepartmentImports extends DepartmentImports
{
    public const TYPE = 'sub_departments';

    public static function fields(): array
    {
        return ['code' => ['Code', false], 'name' => ['Name', true], 'parent_code' => ['Parent department', true], 'cost_centre_code' => ['Cost centre', false],
            'description' => ['Description', false], 'status' => ['Status', false]] + self::applicabilityFields();
    }

    public function label(): string
    {
        return 'Sub departments upload';
    }

    protected function aliases(): array
    {
        return ['sub_department_code' => 'code', 'sub_department_name' => 'name', 'parent_department' => 'parent_code', 'parent_department_code' => 'parent_code', 'department_code' => 'parent_code', 'parent' => 'parent_code',
            'cost_centre' => 'cost_centre_code', 'cost_center_code' => 'cost_centre_code'];
    }

    protected function attributes(array $r, array &$context, ?Model $existing, array &$errors): array
    {
        $attributes = array_filter([
            'name' => $this->text($r['name'] ?? null, 255, 'Name', $errors),
            'cost_centre_id' => $this->lookup($context, CostCentre::class, $r['cost_centre_code'] ?? null, 'Cost centre', $errors),
            'description' => $this->text($r['description'] ?? null, 1000, 'Description', $errors),
            'status' => $this->status($r['status'] ?? null, $errors),
        ], fn ($v) => $v !== null);

        $parentCode = $r['parent_code'] ?? null;
        if ($parentCode !== null && in_array(mb_strtolower(trim($parentCode)), [mb_strtolower(trim((string) ($r['code'] ?? ''))), mb_strtolower(trim((string) ($r['name'] ?? '')))], true)) {
            $errors[] = 'A sub department cannot sit under itself.';

            return $attributes;
        }
        $parentId = $this->parentId(Department::class, $parentCode, $context, 'Parent department', $errors);
        if ($parentId === null) {
            return $attributes;
        }
        if ($existing !== null && $parentId === (int) $existing->getKey()) {
            $errors[] = 'A sub department cannot sit under itself.';

            return $attributes;
        }
        $attributes['parent_department_id'] = $parentId;
        if ($parentId < 0) {
            return $attributes;
        }
        $parent = Department::query()->withoutGlobalScope(AccessScope::class)->find($parentId);
        if ($existing === null) {
            if ($parent->company_id !== null) {
                $attributes['company_id'] = (int) $parent->company_id;
            }
        } elseif ((int) $existing->company_id !== (int) $parent->company_id) {
            $errors[] = "'{$existing->name}' and department '{$parent->name}' are in different companies: a sub department sits under a department of its own company.";
        }
        if ($existing !== null) {
            try {
                app(DepartmentHierarchy::class)->assertParent($existing, $parentId);
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        return $attributes;
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Code', 'No', ReferenceGuide::ownCode('sub department'), ''],
                ['Name', 'Yes', 'The sub department name.', 'Quality assurance'],
                ['Parent department', 'Yes', ReferenceGuide::name('parent department', 'Organisation → Departments, or an earlier row of this file'), 'Engineering'],
                ['Cost centre', 'No', ReferenceGuide::link('cost centre', 'Organisation → Cost centres'), 'Head office'],
                ['Description', 'No', 'What it does.', 'Testing and release quality'],
                ['Status', 'No', 'active or inactive. Blank for a new one is active.', 'active'],
                ...self::applicabilityColumns(),
            ],
            'notes' => [
                ReferenceGuide::OWN_CODE_NOTE,
                ReferenceGuide::NOTE,
                'A new sub department is added in its parent\'s company; a department on file moves under the parent (it must be in the same company).',
                'A department never sits under itself or one of its own sub departments. List a department before the sub departments under it when both are in the file.',
                'Blank cells keep what is on file. Changes to an existing department follow the Configuration Change Centre when your organisation approves configuration changes.',
            ],
        ];
    }

    protected function examples(): array
    {
        $parent = Department::query()->withoutGlobalScope(AccessScope::class)->whereNull('parent_department_id')->orderBy('id')->value('name') ?? 'Engineering';
        $costCentre = CostCentre::query()->withoutGlobalScope(AccessScope::class)->orderBy('id')->value('name') ?? '';

        return [['', 'Quality assurance', $parent, $costCentre, 'Testing and release quality', 'active'], ['', 'Platform', $parent, $costCentre, 'Shared services and tooling', 'active']];
    }
}
