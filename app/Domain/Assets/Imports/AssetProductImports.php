<?php

namespace App\Domain\Assets\Imports;

use App\Domain\Assets\Models\AssetCategory;
use App\Domain\Assets\Models\AssetModel;
use App\Domain\Assets\Models\AssetSubtype;
use App\Domain\Configuration\Services\ConfigurationChanges;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;

/**
 * Data import DI-35 ("Products", HROne Import → Asset): the products (make and model, AssetModel) of each asset type, as
 * Assets → Asset models keeps them. A product has no code, so a row is matched on asset type + manufacturer + name: one on
 * file is updated (through the Configuration Change Centre when the organisation makes products wait for approval), any
 * other is created. The model's own rules apply (the sub type belongs to the type; known deployment types and licence
 * bases); blank cells keep what is on file.
 */
final class AssetProductImports extends RowImports
{
    public const TYPE = 'asset_products';

    public static function fields(): array
    {
        return ['type_code' => ['Asset type code', true], 'manufacturer' => ['Manufacturer', false], 'name' => ['Product name', true],
            'subtype_code' => ['Sub type code', false], 'description' => ['Description', false], 'life_months' => ['Useful life (months)', false],
            'deployment_type' => ['Deployment type', false], 'licence_basis' => ['Licence basis', false], 'status' => ['Status', false]];
    }

    public function label(): string
    {
        return 'Asset product upload';
    }

    protected function module(): string
    {
        return 'assets';
    }

    protected function entityType(): string
    {
        return AssetModel::class;
    }

    protected function aliases(): array
    {
        return ['asset_type' => 'type_code', 'type' => 'type_code', 'make' => 'manufacturer', 'brand' => 'manufacturer', 'model' => 'name', 'model_name' => 'name',
            'product' => 'name', 'sub_type' => 'subtype_code', 'sub_type_code' => 'subtype_code', 'life' => 'life_months', 'useful_life' => 'life_months'];
    }

    protected function prepare(EmployeeImport $import): array
    {
        return ['seen' => [],
            'types' => AssetCategory::query()->get(['id', 'code'])->mapWithKeys(fn (AssetCategory $c) => [mb_strtolower($c->code) => (int) $c->id])->all(),
            'subtypes' => AssetSubtype::query()->get(['id', 'code', 'asset_category_id'])->keyBy(fn (AssetSubtype $s) => mb_strtolower($s->code))->all()];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        $errors = [];
        $attributes = $this->attributes($r, $context, $errors);
        if ($errors !== []) {
            return ['action' => 'error', 'errors' => $errors];
        }
        $key = $attributes['asset_category_id'].'|'.mb_strtolower($attributes['manufacturer'] ?? '').'|'.mb_strtolower($attributes['name']);
        if (isset($context['seen'][$key])) {
            return ['action' => 'error', 'errors' => ['The same product is in row '.$context['seen'][$key].'.']];
        }
        $context['seen'][$key] = $row->row_number;
        $existing = $this->find($attributes);
        if ($existing === null) {
            return ['action' => 'create'];
        }
        $changed = array_keys(array_filter($this->changeable($attributes), fn ($value, $column) => (string) ($existing->getRawOriginal($column) ?? '') !== (string) $value, ARRAY_FILTER_USE_BOTH));

        return $changed === [] ? ['action' => 'skip', 'match' => ['id' => $existing->getKey(), 'reason' => 'Already on file with these values.']]
            : ['action' => 'update', 'match' => ['id' => $existing->getKey(), 'changes' => $changed]];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $errors = [];
        $context = $this->prepare($import);
        $attributes = $this->attributes($r, $context, $errors);
        if ($errors !== []) {
            throw new \RuntimeException(implode(' ', $errors));
        }
        $reason = $this->label()." #{$import->getKey()} ({$import->original_name})";
        $label = trim(($attributes['manufacturer'] ?? '').' '.$attributes['name']);
        $existing = $this->find($attributes);
        if ($existing === null) {
            (new AssetModel($attributes + ['status' => 'active']))->withAuditReason($reason)->save();

            return "Created {$label}";
        }
        // The type, manufacturer and name are how the product was matched: they keep the spelling on file.
        $attributes = $this->changeable($attributes);
        $changes = app(ConfigurationChanges::class);
        if ($changes->isGoverned($existing) && $changes->requiresApproval($existing)) {
            $change = $changes->propose($existing, $attributes, $reason);

            return "Change #{$change->getKey()} for {$label} waits for approval in the Configuration Change Centre";
        }
        $existing->withAuditReason($reason)->update($attributes);

        return "Updated {$label}";
    }

    /** @param list<string> $errors @return array<string, mixed> the model attributes of the row (blank cells left out) */
    private function attributes(array $r, array $context, array &$errors): array
    {
        $attributes = [];
        $typeId = $context['types'][mb_strtolower($r['type_code'] ?? '')] ?? null;
        $typeId === null ? $errors[] = "Asset type code '".($r['type_code'] ?? '')."' does not exist." : $attributes['asset_category_id'] = $typeId;
        $name = trim((string) ($r['name'] ?? ''));
        $name === '' || mb_strlen($name) > 255 ? $errors[] = 'Product name is required (at most 255 characters).' : $attributes['name'] = $name;
        if (isset($r['manufacturer'])) {
            $attributes['manufacturer'] = mb_substr($r['manufacturer'], 0, 255);
        }
        if (isset($r['subtype_code'])) {
            $subtype = $context['subtypes'][mb_strtolower($r['subtype_code'])] ?? null;
            if ($subtype === null) {
                $errors[] = "Sub type code '{$r['subtype_code']}' does not exist.";
            } elseif ($typeId !== null && (int) $subtype->asset_category_id !== $typeId) {
                $errors[] = "The sub type {$r['subtype_code']} belongs to another asset type.";
            } else {
                $attributes['asset_subtype_id'] = (int) $subtype->id;
            }
        }
        if (isset($r['description'])) {
            $attributes['description'] = $r['description'];
        }
        if (isset($r['life_months'])) {
            ctype_digit($r['life_months']) && (int) $r['life_months'] >= 1 ? $attributes['life_months'] = (int) $r['life_months'] : $errors[] = 'Useful life is a whole number of months, at least 1.';
        }
        foreach (['deployment_type' => ['Deployment type', 'peopleos.assets.deployment_types'], 'licence_basis' => ['Licence basis', 'peopleos.assets.licence_bases']] as $field => [$label, $list]) {
            if (! isset($r[$field])) {
                continue;
            }
            $options = (array) config($list, []);
            $value = mb_strtolower(str_replace([' ', '-', '/'], '_', trim($r[$field])));
            $key = array_key_exists($value, $options) ? $value : array_search(mb_strtolower(trim($r[$field])), array_map('mb_strtolower', $options), true);
            $key === false ? $errors[] = "{$label} must be one of: ".implode(', ', array_keys($options)).'.' : $attributes[$field] = $key;
        }
        if (isset($r['status'])) {
            $status = mb_strtolower(trim($r['status']));
            in_array($status, ['active', 'inactive'], true) ? $attributes['status'] = $status : $errors[] = 'Status must be active or inactive.';
        }

        return $attributes;
    }

    /** @return array<string, mixed> the attributes an update may change (not those the product is matched on) */
    private function changeable(array $attributes): array
    {
        return array_diff_key($attributes, array_flip(['asset_category_id', 'manufacturer', 'name']));
    }

    private function find(array $attributes): ?AssetModel
    {
        return AssetModel::query()->withoutGlobalScope(AccessScope::class)->where('asset_category_id', $attributes['asset_category_id'])
            ->whereRaw('lower(name) = ?', [mb_strtolower($attributes['name'])])
            ->where(fn ($q) => ($attributes['manufacturer'] ?? '') === ''
                ? $q->whereNull('manufacturer')->orWhere('manufacturer', '')
                : $q->whereRaw('lower(manufacturer) = ?', [mb_strtolower($attributes['manufacturer'])]))
            ->orderBy('id')->first();
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Asset type code', 'Yes', 'The asset type the product belongs to (Assets → Asset categories).', 'LAPTOP'],
                ['Manufacturer', 'No', 'The make; part of how a product is matched.', 'Lenovo'],
                ['Product name', 'Yes', 'The model name; with the type and manufacturer it identifies the product.', 'ThinkPad T14 Gen 5'],
                ['Sub type code', 'No', 'A sub type of the same asset type.', 'LAPTOP-STD'],
                ['Description', 'No', 'Free text.', '14-inch, 32 GB, 1 TB'],
                ['Useful life (months)', 'No', 'Overrides the asset type\'s useful life.', '48'],
                ['Deployment type', 'No', 'One of: '.implode(', ', array_keys((array) config('peopleos.assets.deployment_types', []))).'.', 'hardware'],
                ['Licence basis', 'No', 'For software: one of '.implode(', ', array_keys((array) config('peopleos.assets.licence_bases', []))).'.', ''],
                ['Status', 'No', 'active or inactive; a new product is active.', 'active'],
            ],
            'notes' => [
                'A product is matched on asset type + manufacturer + product name (case does not matter): on file it is updated, otherwise created.',
                'Blank cells keep what is on file. Load the asset types (and sub types) first.',
            ],
        ];
    }

    protected function examples(): array
    {
        $type = AssetCategory::query()->where('status', 'active')->orderBy('id')->first(['id', 'code']);
        $code = $type?->code ?? 'LAPTOP';
        $subtype = $type === null ? '' : (string) AssetSubtype::query()->where('asset_category_id', $type->id)->orderBy('id')->value('code');

        return [[$code, 'Lenovo', 'ThinkPad T14 Gen 5', $subtype, '14-inch, 32 GB, 1 TB', '48', 'hardware', '', 'active'], [$code, 'Apple', 'MacBook Pro 14 M4', '', '14-inch, 24 GB', '48', 'hardware', '', 'active']];
    }
}
