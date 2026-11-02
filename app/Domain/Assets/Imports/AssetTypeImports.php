<?php

namespace App\Domain\Assets\Imports;

use App\Domain\Assets\Models\AssetCategory;
use App\Domain\Employment\Imports\MasterImports;
use App\Domain\Identity\Models\Role;
use Illuminate\Database\Eloquent\Model;

/**
 * Data import DI-35 ("Asset types", HROne Import → Asset; matrix 26 XO-11): asset types (AssetCategory) keyed by code, as
 * Assets → Asset categories keeps them — a new code creates the type, a code on file updates it (through the Configuration
 * Change Centre when the organisation makes asset types wait for approval), blank cells keep what is on file.
 */
final class AssetTypeImports extends MasterImports
{
    public const TYPE = 'asset_types';

    public static function fields(): array
    {
        return ['code' => ['Code', true], 'name' => ['Name', true], 'requires_serial' => ['Serial number required', false], 'is_it_asset' => ['IT asset', false],
            'default_life_months' => ['Useful life (months)', false], 'owner_role' => ['Owner role', false], 'fulfilment_days' => ['Due days', false], 'status' => ['Status', false]];
    }

    public function label(): string
    {
        return 'Asset type upload';
    }

    protected function module(): string
    {
        return 'assets';
    }

    protected function model(): string
    {
        return AssetCategory::class;
    }

    protected function aliases(): array
    {
        return ['asset_type_code' => 'code', 'type_code' => 'code', 'asset_type' => 'name', 'asset_type_name' => 'name', 'serial_required' => 'requires_serial',
            'it_asset' => 'is_it_asset', 'useful_life' => 'default_life_months', 'life_months' => 'default_life_months', 'role' => 'owner_role', 'due_days' => 'fulfilment_days'];
    }

    protected function attributes(array $r, array &$context, ?Model $existing, array &$errors): array
    {
        $attributes = [];
        if (isset($r['name'])) {
            mb_strlen($r['name']) > 255 ? $errors[] = 'Name is at most 255 characters.' : $attributes['name'] = $r['name'];
        }
        if ($existing === null && ! preg_match('/^[A-Za-z0-9_-]{1,32}$/', (string) ($r['code'] ?? ''))) {
            $errors[] = 'Code is up to 32 letters, digits, dashes or underscores.';
        }
        foreach (['requires_serial' => 'Serial number required', 'is_it_asset' => 'IT asset'] as $field => $label) {
            $flag = $this->flag($r[$field] ?? null, $label, $errors);
            if ($flag !== null) {
                $attributes[$field] = $flag;
            }
        }
        if (isset($r['default_life_months'])) {
            ctype_digit($r['default_life_months']) && (int) $r['default_life_months'] >= 1 ? $attributes['default_life_months'] = (int) $r['default_life_months'] : $errors[] = 'Useful life is a whole number of months, at least 1.';
        }
        if (isset($r['fulfilment_days'])) {
            ctype_digit($r['fulfilment_days']) && (int) $r['fulfilment_days'] <= 365 ? $attributes['fulfilment_days'] = (int) $r['fulfilment_days'] : $errors[] = 'Due days is a whole number from 0 to 365.';
        }
        if (isset($r['owner_role'])) {
            $context['roles'] ??= Role::query()->get(['id', 'slug', 'name'])->flatMap(fn (Role $role) => [mb_strtolower((string) $role->slug) => (int) $role->id, mb_strtolower((string) $role->name) => (int) $role->id])->all();
            $id = $context['roles'][mb_strtolower($r['owner_role'])] ?? null;
            $id === null ? $errors[] = "Role '{$r['owner_role']}' does not exist (Administration → Roles: its name or key)." : $attributes['owner_role_id'] = $id;
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
                ['Code', 'Yes', 'The asset type\'s code (up to 32 letters, digits, dashes or underscores); it never changes once created.', 'LAPTOP'],
                ['Name', 'Yes', 'The asset type\'s name (needed for a new type).', 'Laptop'],
                ['Serial number required', 'No', 'yes or no: every asset of this type needs a serial number.', 'yes'],
                ['IT asset', 'No', 'yes or no: IT asks for it back at exit (IT clearance).', 'yes'],
                ['Useful life (months)', 'No', 'Months the asset is used before it is replaced (depreciation).', '36'],
                ['Owner role', 'No', 'The role that fulfils requests for this type: its name or key (Administration → Roles).', 'asset-admin'],
                ['Due days', 'No', 'Days the role has to fulfil a request, 0 to 365.', '5'],
                ['Status', 'No', 'active or inactive; a new type is active.', 'active'],
            ],
            'notes' => [
                'Rows are matched on Code: a code on file updates the type, any other code creates one. A code may appear once per file.',
                'Blank cells keep what is on file. The code itself is never changed.',
            ],
        ];
    }

    protected function examples(): array
    {
        $role = Role::query()->where('slug', 'asset-admin')->value('slug') ?? '';

        return [['LAPTOP', 'Laptop', 'yes', 'yes', '36', $role, '5', 'active'], ['FURN', 'Furniture', 'no', 'no', '60', '', '10', 'active']];
    }
}
