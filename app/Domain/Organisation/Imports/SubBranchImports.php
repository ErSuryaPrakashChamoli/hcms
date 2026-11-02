<?php

namespace App\Domain\Organisation\Imports;

use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Location;
use Illuminate\Database\Eloquent\Model;

/**
 * Data import DI-1 (Core → Sub branches): locations inside a branch, by code, with the parent branch's code. A new sub
 * branch takes its branch's company (and, when its cells are blank, the branch's country, time zone, region and
 * business region); a location on file moves under the named branch, which must be in its company. A location never
 * sits inside itself or one of its own sub branches.
 */
final class SubBranchImports extends LocationImports
{
    public const TYPE = 'sub_branches';

    public static function fields(): array
    {
        return [
            'code' => ['Code', true], 'name' => ['Name', true], 'parent_code' => ['Parent branch code', true], 'type' => ['Type', false],
            'address_line_1' => ['Address line 1', false], 'address_line_2' => ['Address line 2', false], 'city' => ['City', false],
            'state_code' => ['State code', false], 'postal_code' => ['Postal code', false], 'country_code' => ['Country code', false],
            'timezone' => ['Time zone', false], 'region_code' => ['Region code', false], 'business_region_code' => ['Business region code', false],
            'status' => ['Status', false],
        ];
    }

    public function label(): string
    {
        return 'Sub branches upload';
    }

    protected function aliases(): array
    {
        return ['sub_branch_code' => 'code', 'sub_branch_name' => 'name', 'parent_branch' => 'parent_code', 'branch_code' => 'parent_code', 'branch' => 'parent_code', 'parent' => 'parent_code'] + parent::aliases();
    }

    protected function attributes(array $r, array &$context, ?Model $existing, array &$errors): array
    {
        $attributes = $this->placeAttributes($r, $context, $existing, $errors);
        $parentCode = $r['parent_code'] ?? null;
        $parentId = null;
        if ($parentCode !== null && mb_strtolower(trim($parentCode)) === mb_strtolower(trim((string) ($r['code'] ?? '')))) {
            $errors[] = 'A sub branch cannot sit inside itself.';
        } else {
            $parentId = $this->parentId(Location::class, $parentCode, $context, 'Parent branch code', $errors);
        }
        $attributes['parent_location_id'] = $parentId;

        $parent = $parentId !== null && $parentId > 0 ? Location::query()->withoutGlobalScope(AccessScope::class)->find($parentId) : null;
        if ($parent !== null) {
            if ($existing === null) {
                $attributes['company_id'] = $parent->company_id;
                // A new sub branch takes what its branch has for the cells left blank.
                if (($r['country_code'] ?? '') === '' && $parent->country_code !== null) {
                    $attributes['country_code'] = $parent->country_code;
                }
                $attributes['timezone'] ??= $parent->timezone;
                $attributes['region_id'] ??= $parent->region_id;
                $attributes['business_region_id'] ??= $parent->business_region_id;
            } elseif ((int) $existing->company_id !== (int) $parent->company_id) {
                $errors[] = "'{$existing->code}' and branch '{$parent->code}' are in different companies: a sub branch sits in a branch of its own company.";
            }
        }
        $attributes = array_filter($attributes, fn ($v) => $v !== null);
        $this->checkHierarchy($existing, $attributes, $errors);

        return $attributes;
    }

    public function format(): array
    {
        $format = parent::format();
        $columns = $format['columns'];
        array_splice($columns, 2, 1, [['Parent branch code', 'Yes', 'Code of the branch this location sits in (Organisation → Locations, or an earlier row of this file).', 'BLR']]);
        $columns[0] = ['Code', 'Yes', 'The sub branch code (letters, digits, - and _; up to 32). A code on file updates that location.', 'BLR-WF'];
        $columns[1] = ['Name', 'Yes', 'The sub branch name.', 'Whitefield annexe'];

        return [
            'columns' => $columns,
            'notes' => [
                'Rows are matched on Code (any case). A new code adds a sub branch in the parent branch\'s company; a code on file moves that location under the parent branch (it must be in the same company).',
                'A new sub branch takes the branch\'s country, time zone, region and business region for the cells left blank. Blank cells of a location on file keep what is on file.',
                'A location never sits inside itself or one of its own sub branches. List a branch before the sub branches under it when both are in the file.',
            ],
        ];
    }

    protected function examples(): array
    {
        $parent = Location::query()->withoutGlobalScope(AccessScope::class)->whereNull('parent_location_id')->orderBy('id')->value('code') ?? 'BLR';

        return [
            ['BLR-WF', 'Whitefield annexe', $parent, 'office', '45 ITPL Road', 'Whitefield', 'Bengaluru', 'KA', '560066', '', '', '', '', 'active'],
            ['BLR-WH', 'Hosur Road warehouse', $parent, 'warehouse', '9 Hosur Road', 'Bommanahalli', 'Bengaluru', 'KA', '560068', '', '', '', '', 'active'],
        ];
    }
}
