<?php

namespace App\Domain\Assets\Imports;

use App\Domain\Assets\Models\AssetCategory;
use App\Domain\Assets\Models\AssetModel;
use App\Domain\Assets\Models\AssetSubtype;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\MasterImports;
use App\Support\Imports\ClearValue;
use App\Support\Imports\ReferenceGuide;
use Illuminate\Database\Eloquent\Model;

/**
 * Data import DI-35 ("Asset sub types", HROne Import → Asset): sub types inside an asset type (AssetSubtype), keyed by their
 * code (unique in the organisation, as on Assets → Asset sub types). A sub type's type cannot move once assets or products
 * use it; blank cells keep what is on file. Names first: the asset type is given by name or code, and the sub type's own
 * code may be left blank — the row then changes the sub type of that name within its asset type, or adds one (keyed()).
 */
final class AssetSubtypeImports extends MasterImports
{
    public const TYPE = 'asset_subtypes';

    public static function fields(): array
    {
        return ['code' => ['Code', false], 'type_code' => ['Asset type', true], 'name' => ['Name', true],
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
            'sub_type' => 'name', 'sub_type_name' => 'name', 'allow_duplicate_serial_number' => 'allow_duplicate_serial', 'acknowledgement' => 'acknowledgement_required',
            'asset_type_code' => 'type_code'];
    }

    /**
     * Names first: sub type names repeat across asset types (a "Standard" laptop, a "Standard" phone), so a row that leaves
     * the code blank is matched on its name within its asset type — never on a namesake under another type, which the row
     * would otherwise move — and a new sub type gets a code made from the type's code and its name (LAPTOP_STANDARD). The
     * shared rules (MasterImports::keyed) see the type and the name together, so two rows may not add the same sub type
     * to one type. A row without an asset type is read as in every master upload.
     */
    protected function keyed(array $r, array &$context, EmployeeImportRow $row): array
    {
        $name = trim((string) ($r['name'] ?? ''));
        $type = trim((string) ($r['type_code'] ?? ''));
        if ($name === '' || $type === '' || ClearValue::isClear($name) || ClearValue::isClear($type)) {
            return parent::keyed($r, $context, $row);
        }
        $ignored = [];
        $typeId = $this->lookup($context, AssetCategory::class, $type, 'Asset type', $ignored);
        $typeKey = $typeId !== null && $typeId > 0 ? (string) AssetCategory::query()->whereKey($typeId)->value('code') : $type;
        if (trim((string) ($r['code'] ?? '')) === '' && $typeId !== null && $typeId > 0) {
            $codes = $this->query()->where('asset_category_id', $typeId)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->orderBy('id')->pluck('code')->all();
            if (count($codes) > 1) {
                return [$r, "Several sub types of this asset type are called '{$name}' (".implode(', ', $codes).'): put the code of the one you mean in the Code column.'];
            }
            if ($codes !== []) {
                $r['code'] = (string) $codes[0];
            }
        }
        // The unit separator keeps the joined name from ever matching a sub type's real name.
        [$keyed, $problem] = parent::keyed(['name' => $typeKey." \u{1F} ".$name] + $r, $context, $row);

        return [['name' => $r['name']] + $keyed, $problem];
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
        $typeId = $this->lookup($context, AssetCategory::class, $r['type_code'] ?? null, 'Asset type', $errors);
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
                ['Code', 'No', 'Optional. Blank: the sub type of this name within its asset type is changed, and a new one gets a code made from the type and the name (LAPTOP_STANDARD). Give the code to rename a sub type or to choose its code (unique in the organisation; up to 32 letters, digits, dashes or underscores).', ''],
                ['Asset type', 'Yes', ReferenceGuide::name('asset type', 'Assets → Asset categories', 'Or one the Asset types upload adds.'), 'Laptop'],
                ['Name', 'Yes', 'The sub type\'s name.', 'Developer laptop'],
                ['Duplicate serial numbers allowed', 'No', 'yes (default) or no: with no, two assets of this sub type cannot carry the same serial number.', 'no'],
                ['Acknowledgement required', 'No', 'yes or no: the employee acknowledges receipt when it is assigned.', 'yes'],
                ['Sort order', 'No', 'Order in lists (a whole number).', '1'],
                ['Status', 'No', 'active or inactive; a new sub type is active.', 'active'],
            ],
            'notes' => [
                ReferenceGuide::OWN_CODE_NOTE.' A sub type is found by its name within its asset type, so two asset types may each have a "Standard" sub type.',
                ReferenceGuide::NOTE,
                'Load the asset types first.',
                'Blank cells keep what is on file. A sub type used by assets or products stays under its asset type.',
            ],
        ];
    }

    protected function examples(): array
    {
        // Names first: the asset type as people know it, and no codes (made from the type and the name).
        $type = AssetCategory::query()->where('status', 'active')->orderBy('id')->value('name') ?? 'Laptop';

        return [['', $type, 'Standard', 'no', 'yes', '1', 'active'], ['', $type, 'High specification', 'no', 'yes', '2', 'active']];
    }
}
