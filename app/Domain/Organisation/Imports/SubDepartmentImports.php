<?php

namespace App\Domain\Organisation\Imports;

use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\CostCentre;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Services\DepartmentHierarchy;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Data import DI-1 (Core → Sub departments): departments inside a department, by code, with the parent department's
 * code. A new sub department takes its parent's company; a department on file moves under the named parent, which
 * must be in its company. A department never sits under itself or one of its own sub departments (DepartmentHierarchy,
 * as on the department screen), and the organisation designer follows the new parent.
 */
final class SubDepartmentImports extends DepartmentImports
{
    public const TYPE = 'sub_departments';

    public static function fields(): array
    {
        return ['code' => ['Code', true], 'name' => ['Name', true], 'parent_code' => ['Parent department code', true], 'cost_centre_code' => ['Cost centre code', false],
            'description' => ['Description', false], 'status' => ['Status', false]];
    }

    public function label(): string
    {
        return 'Sub departments upload';
    }

    protected function aliases(): array
    {
        return ['sub_department_code' => 'code', 'sub_department_name' => 'name', 'parent_department' => 'parent_code', 'department_code' => 'parent_code', 'parent' => 'parent_code',
            'cost_centre' => 'cost_centre_code', 'cost_center_code' => 'cost_centre_code'];
    }

    protected function attributes(array $r, array &$context, ?Model $existing, array &$errors): array
    {
        $attributes = array_filter([
            'name' => $this->text($r['name'] ?? null, 255, 'Name', $errors),
            'cost_centre_id' => $this->lookup($context, CostCentre::class, $r['cost_centre_code'] ?? null, 'Cost centre code', $errors),
            'description' => $this->text($r['description'] ?? null, 1000, 'Description', $errors),
            'status' => $this->status($r['status'] ?? null, $errors),
        ], fn ($v) => $v !== null);

        $parentCode = $r['parent_code'] ?? null;
        if ($parentCode !== null && mb_strtolower(trim($parentCode)) === mb_strtolower(trim((string) ($r['code'] ?? '')))) {
            $errors[] = 'A sub department cannot sit under itself.';

            return $attributes;
        }
        $parentId = $this->parentId(Department::class, $parentCode, $context, 'Parent department code', $errors);
        if ($parentId === null) {
            return $attributes;
        }
        $attributes['parent_department_id'] = $parentId;
        if ($parentId === -1) {
            return $attributes;
        }
        $parent = Department::query()->withoutGlobalScope(AccessScope::class)->find($parentId);
        if ($existing === null) {
            if ($parent->company_id !== null) {
                $attributes['company_id'] = (int) $parent->company_id;
            }
        } elseif ((int) $existing->company_id !== (int) $parent->company_id) {
            $errors[] = "'{$existing->code}' and department '{$parent->code}' are in different companies: a sub department sits under a department of its own company.";
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
                ['Code', 'Yes', 'The sub department code (up to 32 characters). A code on file updates that department.', 'ENG-QA'],
                ['Name', 'Yes', 'The sub department name.', 'Quality assurance'],
                ['Parent department code', 'Yes', 'Code of the department it sits under (Organisation → Departments, or an earlier row of this file).', 'ENG'],
                ['Cost centre code', 'No', 'Code of its cost centre (Organisation → Cost centres).', 'CC-100'],
                ['Description', 'No', 'What it does.', 'Testing and release quality'],
                ['Status', 'No', 'active or inactive. Blank for a new one is active.', 'active'],
            ],
            'notes' => [
                'Rows are matched on Code (any case). A new code adds a sub department in its parent\'s company; a code on file moves that department under the parent (it must be in the same company).',
                'A department never sits under itself or one of its own sub departments. List a department before the sub departments under it when both are in the file.',
                'Blank cells keep what is on file. Changes to an existing department follow the Configuration Change Centre when your organisation approves configuration changes.',
            ],
        ];
    }

    protected function examples(): array
    {
        $parent = Department::query()->withoutGlobalScope(AccessScope::class)->whereNull('parent_department_id')->orderBy('id')->value('code') ?? 'ENG';
        $costCentre = CostCentre::query()->withoutGlobalScope(AccessScope::class)->orderBy('id')->value('code') ?? '';

        return [['ENG-QA', 'Quality assurance', $parent, $costCentre, 'Testing and release quality', 'active'], ['ENG-PLAT', 'Platform', $parent, $costCentre, 'Shared services and tooling', 'active']];
    }
}
