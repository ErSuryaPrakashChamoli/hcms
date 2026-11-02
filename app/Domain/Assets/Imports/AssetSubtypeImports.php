<?php

namespace App\Domain\Assets\Imports;

use App\Domain\Assets\Models\AssetCategory;
use App\Domain\Assets\Models\AssetModel;
use App\Domain\Assets\Models\AssetSubtype;
use App\Domain\Employment\Imports\MasterImports;
use Illuminate\Database\Eloquent\Model;

/**
 * Data import DI-35 ("Asset sub types", HROne Import → Asset): sub types inside an asset type (AssetSubtype), keyed by their
 * code (unique in the organisation, as on Assets → Asset sub types). A sub type's type cannot move once assets or products
 * use it; blank cells keep what is on file.
 */
final class AssetSubtypeImports extends MasterImports
{
    public const TYPE = 'asset_subtypes';

    public static function fields(): array
    {
        return ['code' => ['Code', true], 'type_code' => ['Asset type code', true], 'name' => ['Name', true],
            'allow_duplicate_serial' => ['Duplicate serial numbers allowed', false], 'acknowledgement_required' => ['Acknowledgement required', false],
            'sort_order' => ['Sort order', false], 'status' => ['Status', false]];
    }

    public function label(): string
    {
        return 'Asset sub type upload';
    }

    protected function module(): string
    {
        return 'assets';
    }

    protected function model(): string
    {
        return AssetSubtype::class;
    }

    protected function aliases(): array
    {
        return ['sub_type_code' => 'code', 'subtype_code' => 'code', 'asset_sub_type_code' => 'code', 'asset_type' => 'type_code', 'type' => 'type_code',
            'sub_type' => 'name', 'sub_type_name' => 'name', 'allow_duplicate_serial_number' => 'allow_duplicate_serial', 'acknowledgement' => 'acknowledgement_required'];
    }

    protected function attributes(array $r, array &$context, ?Model $existing, array &$errors): array
    {
        $attributes = [];
        if ($existing === null && ! preg_match('/^[A-Za-z0-9_-]{1,32}$/', (string) ($r['code'] ?? ''))) {
            $errors[] = 'Code is up to 32 letters, digits, dashes or underscores.';
        }
        if (isset($r['name'])) {
            mb_strlen($r['name']) > 255 ? $errors[] = 'Name is at most 255 characters.' : $attributes['name'] = $r['name'];
        }
        $typeId = $this->lookup($context, AssetCategory::class, $r['type_code'] ?? null, 'Asset type code', $errors);
        if ($typeId !== null) {
            if ($existing !== null && (int) $existing->asset_category_id !== $typeId
                && ($existing->assets()->exists() || AssetModel::query()->where('asset_subtype_id', $existing->getKey())->exists())) {
                $errors[] = 'The sub type is used by assets or products of its type; it cannot move to another asset type.';
            }
            $attributes['asset_category_id'] = $typeId;
        }
        foreach (['allow_duplicate_serial' => 'Duplicate serial numbers allowed', 'acknowledgement_required' => 'Acknowledgement required'] as $field => $label) {
            $flag = $this->flag($r[$field] ?? null, $label, $errors);
            if ($flag !== null) {
                $attributes[$field] = $flag;
            }
        }
        if (isset($r['sort_order'])) {
            ctype_digit($r['sort_order']) ? $attributes['sort_order'] = (int) $r['sort_order'] : $errors[] = 'Sort order is a whole number.';
        }
        $status = $this->status($r['status'] ?? null, $errors);
        if ($status !== null) {
            $attributes['status'] = $status;
        }

        return $attributes;
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Code', 'Yes', 'The sub type\'s code, unique in the organisation (up to 32 letters, digits, dashes or underscores).', 'LAPTOP-DEV'],
                ['Asset type code', 'Yes', 'The asset type it belongs to (Assets → Asset categories, or the Asset types upload).', 'LAPTOP'],
                ['Name', 'Yes', 'The sub type\'s name.', 'Developer laptop'],
                ['Duplicate serial numbers allowed', 'No', 'yes (default) or no: with no, two assets of this sub type cannot carry the same serial number.', 'no'],
                ['Acknowledgement required', 'No', 'yes or no: the employee acknowledges receipt when it is assigned.', 'yes'],
                ['Sort order', 'No', 'Order in lists (a whole number).', '1'],
                ['Status', 'No', 'active or inactive; a new sub type is active.', 'active'],
            ],
            'notes' => [
                'Rows are matched on Code: a code on file updates the sub type, any other code creates one. Load the asset types first.',
                'Blank cells keep what is on file. A sub type used by assets or products stays under its asset type.',
            ],
        ];
    }

    protected function examples(): array
    {
        $type = AssetCategory::query()->where('status', 'active')->orderBy('id')->value('code') ?? 'LAPTOP';

        return [["{$type}-STD", $type, 'Standard', 'no', 'yes', '1', 'active'], ["{$type}-PRO", $type, 'High specification', 'no', 'yes', '2', 'active']];
    }
}
