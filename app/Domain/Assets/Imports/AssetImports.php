<?php

namespace App\Domain\Assets\Imports;

use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetCategory;
use App\Domain\Assets\Models\AssetModel;
use App\Domain\Assets\Models\AssetSubtype;
use App\Domain\Assets\Services\Assets;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use RuntimeException;

/**
 * Data import DI-35 ("Assets", HROne Import → Asset; matrix 26 XO-11): the asset register, keyed by asset tag, through the
 * Assets service as the asset screens use it.
 *
 * - A new tag is received into stock (Assets::receive: a "procured" movement dated the purchase date). The row's status
 *   then moves it on through the same service: assigned (to the custodian given, Assets::assign — the asset policy's
 *   mapping applies), in repair (sendForRepair), lost (markLost) or retired (retire). A failing step leaves nothing behind.
 * - A tag on file is updated like the asset's edit page: register fields only, and a new location through relocate() (a
 *   movement). Status and custodian change only through the asset's actions, so a row that would change them is refused.
 * - Decision D35-6: a serial number is unique per product (make and model) — per asset type for assets without a product —
 *   in the file and against the register, on top of the sub type's own rule; a type that requires a serial number gets one.
 */
final class AssetImports extends RowImports
{
    public const TYPE = 'assets';

    /** Statuses a new asset can be loaded in (the others need a person on the asset's page: in transit, disposed). */
    public const LOAD_STATUSES = ['in_stock', 'assigned', 'in_repair', 'lost', 'retired'];

    public static function fields(): array
    {
        return [
            'asset_tag' => ['Asset tag', true], 'name' => ['Asset name', false], 'type_code' => ['Asset type code', false], 'subtype_code' => ['Sub type code', false],
            'product' => ['Product name', false], 'manufacturer' => ['Manufacturer', false], 'serial_number' => ['Serial number', false],
            'company_code' => ['Company code', false], 'location_code' => ['Location code', false], 'status' => ['Status', false], 'condition' => ['Condition', false],
            'custodian_code' => ['Custodian employee code', false], 'assigned_on' => ['Assigned on', false], 'purchase_date' => ['Purchase date', false],
            'purchase_cost' => ['Purchase cost', false], 'vendor' => ['Vendor name', false], 'invoice_number' => ['Invoice number', false],
            'warranty_until' => ['Warranty until', false], 'notes' => ['Notes', false],
        ];
    }

    public function label(): string
    {
        return 'Asset register upload';
    }

    protected function module(): string
    {
        return 'assets';
    }

    protected function entityType(): string
    {
        return Asset::class;
    }

    protected function aliases(): array
    {
        return ['tag' => 'asset_tag', 'asset_code' => 'asset_tag', 'asset_type' => 'type_code', 'type' => 'type_code', 'sub_type' => 'subtype_code', 'sub_type_code' => 'subtype_code',
            'model' => 'product', 'product_name' => 'product', 'make' => 'manufacturer', 'serial' => 'serial_number', 'serial_no' => 'serial_number', 'location' => 'location_code',
            'branch' => 'location_code', 'company' => 'company_code', 'custodian' => 'custodian_code', 'employee_code' => 'custodian_code', 'assigned_to' => 'custodian_code',
            'cost' => 'purchase_cost', 'purchase_price' => 'purchase_cost', 'vendor_name' => 'vendor', 'invoice' => 'invoice_number', 'warranty' => 'warranty_until'];
    }

    protected function prepare(EmployeeImport $import): array
    {
        $codes = fn (string $class) => $class::query()->withoutGlobalScope(AccessScope::class)->get(['id', 'code'])->mapWithKeys(fn ($m) => [mb_strtolower((string) $m->code) => (int) $m->id])->all();

        return parent::prepare($import) + ['types' => AssetCategory::query()->get()->keyBy(fn (AssetCategory $c) => mb_strtolower($c->code))->all(),
            'subtypes' => AssetSubtype::query()->get()->keyBy(fn (AssetSubtype $s) => mb_strtolower($s->code))->all(),
            'companies' => $codes(Company::class), 'locations' => Location::query()->withoutGlobalScope(AccessScope::class)->get(['id', 'code', 'company_id'])->keyBy(fn (Location $l) => mb_strtolower((string) $l->code))->all(),
            'serials' => []];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        $tag = mb_strtoupper(trim((string) ($r['asset_tag'] ?? '')));
        if ($tag === '' || mb_strlen($tag) > 64) {
            return ['action' => 'error', 'errors' => ['Asset tag is required (at most 64 characters).']];
        }
        if (isset($context['seen'][$tag])) {
            return ['action' => 'error', 'errors' => ["The same asset tag is in row {$context['seen'][$tag]}."]];
        }
        $context['seen'][$tag] = $row->row_number;
        $existing = $this->find($tag);
        $errors = [];
        $plan = $this->plan($r, $context, $existing, $errors);
        if (isset($r['serial_number'])) {
            // Decision D35-6: a serial number is unique per product (make and model), or per asset type without a product.
            $model = $plan['attributes']['asset_model_id'] ?? $existing?->asset_model_id;
            $serial = ($model !== null ? "m{$model}" : 't'.($plan['attributes']['asset_category_id'] ?? $existing?->asset_category_id)).'|'.mb_strtolower(trim($r['serial_number']));
            if (isset($context['serials'][$serial])) {
                $errors[] = "The same serial number of the same product is in row {$context['serials'][$serial]}.";
            }
            $context['serials'][$serial] = $row->row_number;
        }
        if ($errors !== []) {
            return ['action' => 'error', 'errors' => array_values(array_unique($errors)), 'employee_id' => $plan['custodian'] ?? null];
        }
        if ($existing === null) {
            return ['action' => 'create', 'match' => ['status' => $plan['status']], 'employee_id' => $plan['custodian']];
        }
        $changed = array_keys(array_filter($plan['attributes'], fn ($value, $column) => ! $this->same($existing, $column, $value), ARRAY_FILTER_USE_BOTH));
        if ($plan['location'] !== null && (int) $existing->location_id !== $plan['location']) {
            $changed[] = 'location_id';
        }

        return $changed === []
            ? ['action' => 'skip', 'match' => ['id' => $existing->getKey(), 'reason' => 'Already on file with these values.']]
            : ['action' => 'update', 'match' => ['id' => $existing->getKey(), 'changes' => $changed]];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $context = $this->prepare($import);
        $tag = mb_strtoupper(trim((string) $r['asset_tag']));
        $existing = $this->find($tag);
        $errors = [];
        $plan = $this->plan($r, $context, $existing, $errors);
        if ($errors !== []) {
            throw new RuntimeException(implode(' ', $errors));
        }
        $assets = app(Assets::class);
        $reason = $this->label()." #{$import->getKey()} ({$import->original_name})";

        if ($existing !== null) {
            if ($plan['attributes'] !== []) {
                $existing->withAuditReason($reason)->update($plan['attributes']);
            }
            if ($plan['location'] !== null && (int) $existing->location_id !== $plan['location']) {
                $assets->relocate($existing->refresh(), $plan['location'], $reason, $user);
            }

            return "Updated {$tag}";
        }

        $asset = $assets->receive($plan['attributes'] + ['asset_tag' => $tag, 'location_id' => $plan['location']], $user);
        $note = $r['notes'] ?? 'Loaded from the asset register upload';
        match ($plan['status']) {
            'assigned' => $assets->assign($asset, Employee::query()->findOrFail($plan['custodian']), $plan['assigned_on'], null, $note, null, $user),
            'in_repair' => $assets->sendForRepair($asset, $note, $plan['attributes']['vendor'] ?? null, $user),
            'lost' => $assets->markLost($asset, $note, $user),
            'retired' => $assets->retire($asset, $note, $user),
            default => null,
        };

        return "Created {$tag}".($plan['status'] === 'in_stock' ? ' in stock' : ', '.config("peopleos.assets.statuses.{$plan['status']}", $plan['status']));
    }

    /**
     * What the row does: the register attributes (blank cells left out), the location, and for a new asset its status,
     * custodian and assignment date. Problems go into $errors.
     *
     * @param  list<string>  $errors
     * @return array{attributes: array<string, mixed>, location: ?int, status: string, custodian: ?int, assigned_on: ?string}
     */
    private function plan(array $r, array $context, ?Asset $existing, array &$errors): array
    {
        $attributes = [];
        $new = $existing === null;
        if (isset($r['name'])) {
            mb_strlen($r['name']) > 255 ? $errors[] = 'Asset name is at most 255 characters.' : $attributes['name'] = $r['name'];
        } elseif ($new) {
            $errors[] = 'Asset name is required for a new asset.';
        }

        // Type, sub type and product.
        /** @var ?AssetCategory $type */
        $type = isset($r['type_code']) ? ($context['types'][mb_strtolower($r['type_code'])] ?? null) : null;
        if (isset($r['type_code']) && $type === null) {
            $errors[] = "Asset type code '{$r['type_code']}' does not exist.";
        } elseif ($type === null && $new) {
            $errors[] = 'Asset type code is required for a new asset.';
        }
        if ($type !== null && $existing !== null && (int) $existing->asset_category_id !== (int) $type->id) {
            $errors[] = 'An asset keeps its asset type; register a new asset instead.';
        }
        $typeId = $type?->id ?? $existing?->asset_category_id;
        if ($type !== null) {
            $attributes['asset_category_id'] = (int) $type->id;
        }
        if (isset($r['subtype_code'])) {
            $subtype = $context['subtypes'][mb_strtolower($r['subtype_code'])] ?? null;
            if ($subtype === null) {
                $errors[] = "Sub type code '{$r['subtype_code']}' does not exist.";
            } elseif ($typeId !== null && (int) $subtype->asset_category_id !== (int) $typeId) {
                $errors[] = "The sub type {$subtype->code} belongs to another asset type.";
            } else {
                $attributes['asset_subtype_id'] = (int) $subtype->id;
            }
        }
        if (isset($r['product'])) {
            $products = AssetModel::query()->where('asset_category_id', $typeId ?? 0)->whereRaw('lower(name) = ?', [mb_strtolower($r['product'])])
                ->when(isset($r['manufacturer']), fn ($q) => $q->whereRaw('lower(manufacturer) = ?', [mb_strtolower($r['manufacturer'])]))->get(['id', 'asset_subtype_id']);
            if ($products->count() === 1) {
                $attributes['asset_model_id'] = (int) $products->first()->id;
            } else {
                $errors[] = $products->isEmpty() ? "Product '{$r['product']}' is not a product of this asset type (load it with the Products upload)." : "More than one product is named '{$r['product']}': give the manufacturer.";
            }
        }

        // Serial number: unique in the register for an upload; required when the type says so.
        if (isset($r['serial_number'])) {
            $serial = trim($r['serial_number']);
            if (preg_match('/^\d+(\.\d+)?e\+?\d+$/i', $serial)) {
                $errors[] = 'The serial number was turned into a number like 5.01E+13 by the spreadsheet; format the column as Text and enter it again.';
            }
            // Decision D35-6: unique per product (make and model) — or per asset type for assets without a product — as asset
            // registers keep serials; the sub type's own "no duplicates" rule still applies when the asset is saved.
            $model = $attributes['asset_model_id'] ?? $existing?->asset_model_id;
            $clash = Asset::query()->withoutGlobalScope(AccessScope::class)->whereRaw('LOWER(TRIM(serial_number)) = ?', [mb_strtolower($serial)])
                ->when($model !== null, fn ($q) => $q->where('asset_model_id', $model), fn ($q) => $q->whereNull('asset_model_id')->where('asset_category_id', $typeId ?? 0))
                ->when($existing !== null, fn ($q) => $q->whereKeyNot($existing->getKey()))->value('asset_tag');
            $clash !== null ? $errors[] = "Serial number {$serial} is already used by asset {$clash} of the same ".($model !== null ? 'product' : 'asset type').'.' : $attributes['serial_number'] = mb_substr($serial, 0, 255);
        } elseif ($new && $type?->requires_serial) {
            $errors[] = "A {$type->name} needs a serial number.";
        }

        // Company and location.
        $location = null;
        if (isset($r['location_code'])) {
            $found = $context['locations'][mb_strtolower($r['location_code'])] ?? null;
            $found === null ? $errors[] = "Location code '{$r['location_code']}' does not exist." : $location = (int) $found->id;
            if ($found !== null && ! isset($r['company_code']) && $found->company_id !== null && $new) {
                $attributes['company_id'] = (int) $found->company_id;
            }
        }
        if (isset($r['company_code'])) {
            $company = $context['companies'][mb_strtolower($r['company_code'])] ?? null;
            $company === null ? $errors[] = "Company code '{$r['company_code']}' does not exist." : $attributes['company_id'] = $company;
        }

        // Purchase and register details.
        foreach (['purchase_date' => 'Purchase date', 'warranty_until' => 'Warranty until'] as $field => $label) {
            if (isset($r[$field])) {
                $date = $this->date($r[$field]);
                $date === null ? $errors[] = "{$label} must be a date (YYYY-MM-DD)." : $attributes[$field] = $date;
            }
        }
        if (isset($attributes['purchase_date']) && $attributes['purchase_date'] > now()->toDateString()) {
            $errors[] = 'Purchase date cannot be in the future.';
        }
        if (isset($r['purchase_cost'])) {
            $cost = str_replace([',', ' '], '', $r['purchase_cost']);
            is_numeric($cost) && (float) $cost >= 0 ? $attributes['purchase_cost'] = round((float) $cost, 2) : $errors[] = 'Purchase cost must be a number, 0 or more.';
        }
        foreach (['vendor' => 'Vendor name', 'invoice_number' => 'Invoice number'] as $field => $label) {
            if (isset($r[$field])) {
                mb_strlen($r[$field]) > 255 ? $errors[] = "{$label} is at most 255 characters." : $attributes[$field] = $r[$field];
            }
        }
        if (isset($r['notes'])) {
            $attributes['notes'] = $r['notes'];
        }
        if (isset($r['condition'])) {
            $condition = mb_strtolower(str_replace(' ', '_', trim($r['condition'])));
            array_key_exists($condition, (array) config('peopleos.assets.conditions', [])) ? $attributes['condition'] = $condition
                : $errors[] = 'Condition must be one of: '.implode(', ', array_keys((array) config('peopleos.assets.conditions', []))).'.';
        }

        // Status and custodian: set for a new asset through the service; never changed from a file once on file.
        $custodian = null;
        if (isset($r['custodian_code'])) {
            $code = mb_strtolower($r['custodian_code']);
            $custodian = $context['employees'][$code] ?? null;
            if ($custodian === null) {
                $errors[] = "Custodian employee code '{$r['custodian_code']}' does not exist.";
            }
        }
        $status = isset($r['status']) ? mb_strtolower(str_replace(' ', '_', trim($r['status']))) : ($custodian !== null ? 'assigned' : 'in_stock');
        $assignedOn = isset($r['assigned_on']) ? $this->date($r['assigned_on']) : null;
        if (isset($r['assigned_on']) && $assignedOn === null) {
            $errors[] = 'Assigned on must be a date (YYYY-MM-DD).';
        }
        if ($new) {
            if (! in_array($status, self::LOAD_STATUSES, true)) {
                $errors[] = 'Status of a new asset must be one of: '.implode(', ', self::LOAD_STATUSES).' (in transit and disposed are recorded on the asset\'s page).';
            } elseif ($status === 'assigned' && $custodian === null) {
                $errors[] = 'An assigned asset needs the custodian\'s employee code.';
            } elseif ($status !== 'assigned' && $custodian !== null) {
                $errors[] = 'Only an assigned asset has a custodian.';
            } elseif ($custodian !== null && ! Employee::query()->findOrFail($custodian)->lifecycle_state->isEmployed()) {
                $errors[] = 'Assets can only be assigned to current employees.';
            }
        } else {
            if (isset($r['status']) && $status !== $existing->status) {
                $errors[] = 'The asset is '.config("peopleos.assets.statuses.{$existing->status}", $existing->status).': change its status with the asset\'s actions (assign, take back, repair, retire …).';
            }
            if ($custodian !== null && (int) $existing->custodian_id !== $custodian) {
                $errors[] = 'Assign, transfer or take back the asset on its page; an upload does not change the custodian.';
            }
        }

        return ['attributes' => $attributes, 'location' => $location, 'status' => $status, 'custodian' => $custodian, 'assigned_on' => $assignedOn];
    }

    private function find(string $tag): ?Asset
    {
        return Asset::query()->withoutGlobalScope(AccessScope::class)->whereRaw('upper(asset_tag) = ?', [$tag])->first();
    }

    /** Whether the asset already holds the value (dates by day, numbers by value). */
    private function same(Asset $asset, string $column, mixed $value): bool
    {
        $stored = $asset->getRawOriginal($column);
        if ($value === null || $stored === null) {
            return $value === $stored;
        }
        if (is_numeric($value) && is_numeric($stored)) {
            return (float) $value === (float) $stored;
        }

        return str_starts_with((string) $stored, (string) $value) && (strlen((string) $value) === 10 || (string) $stored === (string) $value);
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Asset tag', 'Yes', 'The asset\'s tag (code), unique in the organisation; a tag on file updates that asset.', 'LT-0001'],
                ['Asset name', 'No', 'Needed for a new asset.', 'Asha\'s laptop'],
                ['Asset type code', 'No', 'Needed for a new asset (Assets → Asset categories); an asset keeps its type.', 'LAPTOP'],
                ['Sub type code', 'No', 'A sub type of the same asset type; blank takes the product\'s.', ''],
                ['Product name', 'No', 'A product of the asset type (Products upload or Assets → Asset models).', 'ThinkPad T14 Gen 5'],
                ['Manufacturer', 'No', 'The product\'s manufacturer, when two products have the same name.', 'Lenovo'],
                ['Serial number', 'No', 'Unique for the product (make and model), or for the asset type without a product; needed when the asset type requires one. Format the column as Text.', 'PF4ABC123'],
                ['Company code', 'No', 'The company that owns the asset; blank takes the location\'s company.', ''],
                ['Location code', 'No', 'Where the asset is (Organisation → Locations); a new location on file is recorded as a move.', 'DEL'],
                ['Status', 'No', 'For a new asset: '.implode(', ', self::LOAD_STATUSES).' (blank: assigned when a custodian is given, else in stock). Never changed for an asset on file.', 'assigned'],
                ['Condition', 'No', 'One of: '.implode(', ', array_keys((array) config('peopleos.assets.conditions', []))).'.', 'good'],
                ['Custodian employee code', 'No', 'For an assigned asset: who holds it (a current employee).', 'EMP0001'],
                ['Assigned on', 'No', 'The date the custodian received it (YYYY-MM-DD); blank is today.', now()->subMonths(3)->toDateString()],
                ['Purchase date', 'No', 'YYYY-MM-DD, not in the future.', now()->subYear()->toDateString()],
                ['Purchase cost', 'No', 'The purchase cost (digits; commas are ignored).', '95000'],
                ['Vendor name', 'No', 'Who it was bought from.', 'Acme IT Supplies'],
                ['Invoice number', 'No', 'The purchase invoice.', 'INV-2025-118'],
                ['Warranty until', 'No', 'The last day of the warranty (YYYY-MM-DD).', now()->addYears(2)->toDateString()],
                ['Notes', 'No', 'Free text; for a new asset in repair, lost or retired it is the reason recorded.', ''],
            ],
            'notes' => [
                'A new tag is received into stock and then assigned, sent for repair, marked lost or retired as its status says, each step recorded as a movement.',
                'A tag on file is updated like the asset\'s edit page: register details and the location (as a move). Status and custodian change only with the asset\'s actions.',
                'Serial numbers are unique per product (make and model) — per asset type for assets without a product — in the file and against the assets on file.',
                'Blank cells keep what is on file.',
            ],
        ];
    }

    protected function examples(): array
    {
        $type = AssetCategory::query()->where('status', 'active')->orderBy('id')->first(['id', 'code']);
        $employee = Employee::query()->employed()->orderBy('id')->value('employee_code') ?? 'EMP0001';
        $location = (string) (Location::query()->orderBy('id')->value('code') ?? '');
        $product = $type === null ? null : AssetModel::query()->where('asset_category_id', $type->id)->where('status', 'active')->orderBy('id')->first(['name', 'manufacturer']);
        $stamp = strtoupper(base_convert((string) now()->format('ymdHi'), 10, 36));

        return [
            ["AT-{$stamp}-1", 'Laptop 1', $type?->code ?? 'LAPTOP', '', (string) $product?->name, (string) $product?->manufacturer, "SN{$stamp}A", '', $location, 'assigned', 'good', $employee,
                now()->subMonths(3)->toDateString(), now()->subYear()->toDateString(), '95000', 'Acme IT Supplies', 'INV-2025-118', now()->addYears(2)->toDateString(), ''],
            ["AT-{$stamp}-2", 'Laptop 2', $type?->code ?? 'LAPTOP', '', (string) $product?->name, (string) $product?->manufacturer, "SN{$stamp}B", '', $location, 'in_stock', 'new', '',
                '', now()->subMonth()->toDateString(), '87000', 'Acme IT Supplies', 'INV-2025-131', now()->addYears(3)->toDateString(), 'Spare'],
        ];
    }
}
