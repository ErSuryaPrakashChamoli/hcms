<?php

use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetAssignment;
use App\Domain\Assets\Models\AssetCategory;
use App\Domain\Assets\Models\AssetModel;
use App\Domain\Assets\Models\AssetSubtype;
use App\Domain\Assets\Services\Assets;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Exit\Services\Exits;
use App\Domain\Experience\Services\ExperienceTasks;
use App\Domain\Identity\Models\Role;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Resources\AssetCategories\Pages\ManageAssetCategories;
use App\Filament\Resources\AssetModels\Pages\ManageAssetModels;
use App\Filament\Resources\Assets\Pages\CreateAsset;
use App\Filament\Resources\AssetSubtypes\AssetSubtypeResource;
use App\Filament\Resources\AssetSubtypes\Pages\ManageAssetSubtypes;
use App\Http\Controllers\ParityFileController;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

/* HROne setup parity AS-A — Asset taxonomy and masters: sub types (duplicate serials, acknowledgement), the asset
 * type's role and due days, product details and documents, and recovery at exit. Defaults keep earlier behaviour. */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->assets = app(Assets::class);
    $this->laptop = AssetCategory::query()->where('code', 'LAPTOP')->firstOrFail();
    $this->monitor = AssetCategory::query()->where('code', 'MONITOR')->firstOrFail();
    $this->employee = activeEmployee(null, ['asset.own', 'task.view', 'exit.resign']);
});

function svcLaptop(array $attributes): Asset
{
    return app(Assets::class)->receive($attributes + ['asset_category_id' => AssetCategory::query()->where('code', 'LAPTOP')->value('id'), 'name' => 'Laptop']);
}

it('keeps serials, acknowledgement and exit recovery as before by default', function () {
    $subtype = AssetSubtype::query()->create(['asset_category_id' => $this->laptop->id, 'name' => 'Standard series', 'code' => 'std']);
    expect($subtype->code)->toBe('STD')->and($subtype->allow_duplicate_serial)->toBeTrue()->and($subtype->acknowledgement_required)->toBeFalse()
        ->and(app(SettingsRepository::class)->get('asset.exit.recovery_required'))->toBeTrue()
        ->and(app(SettingsRepository::class)->get('asset.exit.recovery_basis'))->toBe('manual');

    svcLaptop(['asset_tag' => 'LT-1', 'serial_number' => 'SN-1']);
    svcLaptop(['asset_tag' => 'LT-2', 'serial_number' => 'SN-1']);
    svcLaptop(['asset_tag' => 'LT-3', 'serial_number' => 'SN-1', 'asset_subtype_id' => $subtype->id]);
    $other = svcLaptop(['asset_tag' => 'LT-4', 'serial_number' => 'SN-1', 'asset_subtype_id' => $subtype->id]);
    expect(Asset::query()->where('serial_number', 'SN-1')->count())->toBe(4);

    $this->assets->assign($other, $this->employee);
    expect(app(ExperienceTasks::class)->for($this->employee->user, $this->employee)->where('domain', 'assets'))->toBeEmpty()
        ->and($this->assets->remindAcknowledgements())->toBe(0)
        ->and($this->assets->suggestedRecovery($this->employee))->toBe(0.0);
});

it('keeps the sub type inside its asset type, follows the product, and refuses duplicate serials where the sub type says so', function () {
    $premium = AssetSubtype::query()->create(['asset_category_id' => $this->laptop->id, 'name' => 'Premium series', 'code' => 'PREM', 'allow_duplicate_serial' => false]);
    $standard = AssetSubtype::query()->create(['asset_category_id' => $this->laptop->id, 'name' => 'Standard series', 'code' => 'STD', 'allow_duplicate_serial' => false]);
    $model = AssetModel::query()->create(['asset_category_id' => $this->laptop->id, 'asset_subtype_id' => $premium->id, 'manufacturer' => 'Dell', 'name' => 'Latitude 9450', 'life_months' => 24]);

    expect(fn () => AssetModel::query()->create(['asset_category_id' => $this->monitor->id, 'asset_subtype_id' => $premium->id, 'name' => 'Wrong']))->toThrow(RuntimeException::class, 'another asset type')
        ->and(fn () => AssetModel::query()->create(['asset_category_id' => $this->laptop->id, 'name' => 'Odd', 'deployment_type' => 'teleport']))->toThrow(RuntimeException::class, 'Unknown deployment_type')
        ->and(fn () => $this->assets->receive(['asset_category_id' => $this->monitor->id, 'asset_subtype_id' => $premium->id, 'asset_tag' => 'MON-1', 'name' => 'Monitor']))->toThrow(RuntimeException::class, 'another asset type');

    $first = svcLaptop(['asset_tag' => 'LT-1', 'serial_number' => 'ABC-1', 'asset_model_id' => $model->id]);
    expect($first->asset_subtype_id)->toBe($premium->id);
    expect(fn () => svcLaptop(['asset_tag' => 'LT-2', 'serial_number' => ' abc-1 ', 'asset_subtype_id' => $premium->id]))->toThrow(RuntimeException::class, 'already used by asset LT-1')
        ->and(Asset::query()->where('asset_tag', 'LT-2')->exists())->toBeFalse();
    $second = svcLaptop(['asset_tag' => 'LT-2', 'serial_number' => 'ABC-1', 'asset_subtype_id' => $standard->id]);
    expect(fn () => $second->update(['asset_subtype_id' => $premium->id]))->toThrow(RuntimeException::class, 'does not allow duplicate serial numbers');
    $first->refresh()->update(['notes' => 'Saving again keeps its own serial']);
    expect($first->refresh()->notes)->toBe('Saving again keeps its own serial');

    // The asset form says so instead of failing.
    Livewire::test(CreateAsset::class)
        ->fillForm(['asset_category_id' => $this->laptop->id, 'asset_subtype_id' => $premium->id, 'asset_tag' => 'LT-9', 'name' => 'Laptop', 'serial_number' => 'ABC-1', 'condition' => 'new'])
        ->call('create')->assertNotified('Not allowed');
    expect(Asset::query()->where('asset_tag', 'LT-9')->exists())->toBeFalse();
});

it('asks for an acknowledgement on My tasks and reminds every N days until the employee acknowledges', function () {
    $kit = AssetSubtype::query()->create(['asset_category_id' => $this->laptop->id, 'name' => 'Joiner kit', 'code' => 'KIT', 'acknowledgement_required' => true]);
    $asset = svcLaptop(['asset_tag' => 'LT-1', 'serial_number' => 'S1', 'asset_subtype_id' => $kit->id]);
    $assignment = $this->assets->assign($asset, $this->employee, '2026-09-21');

    $tasks = app(ExperienceTasks::class)->for($this->employee->user, $this->employee)->where('domain', 'assets')->values();
    expect($tasks)->toHaveCount(1)->and($tasks[0]->title)->toContain('Acknowledge receipt')->and($tasks[0]->reference)->toBe('LT-1')
        ->and($tasks[0]->dueAt->toDateString())->toBe('2026-09-24');

    expect($this->assets->remindAcknowledgements())->toBe(0); // not due yet
    $this->travelTo('2026-09-24 09:00:00');
    $this->artisan('peopleos:assets:acknowledgement-reminders')->assertSuccessful();
    actAsTenant($this->tenant);
    $titles = $this->employee->user->notifications()->get()->map(fn ($n) => (string) data_get($n->data, 'title'));
    expect($titles->filter(fn ($t) => str_contains($t, 'Please acknowledge receipt of Laptop [LT-1]')))->toHaveCount(1)
        ->and($this->assets->remindAcknowledgements())->toBe(0); // once per interval
    $this->travelTo('2026-09-27 09:00:00');
    expect($this->assets->remindAcknowledgements())->toBe(1);

    $this->assets->acknowledge($assignment->refresh());
    $this->travelTo('2026-10-05 09:00:00');
    expect($this->assets->remindAcknowledgements())->toBe(0)
        ->and(app(ExperienceTasks::class)->for($this->employee->user, $this->employee)->where('domain', 'assets'))->toBeEmpty();

    app(SettingsRepository::class)->set('asset.acknowledgement.reminder_days', 0, 'No reminders');
    $other = svcLaptop(['asset_tag' => 'LT-2', 'serial_number' => 'S2', 'asset_subtype_id' => $kit->id]);
    $this->assets->assign($other, $this->employee, '2026-09-01');
    expect($this->assets->remindAcknowledgements())->toBe(0)
        ->and(AssetAssignment::query()->where('asset_id', $other->id)->value('acknowledgement_reminded_at'))->toBeNull();
});

it('suggests book value, tells the asset type\'s role, and may waive recovery with a reason at exit', function () {
    $owners = Role::factory()->create(['name' => 'IT store']);
    $keeper = tenantUser($this->tenant, ['exit.clear', 'asset.assign']);
    $owners->users()->attach($keeper);
    $this->laptop->update(['owner_role_id' => $owners->id, 'fulfilment_days' => 5, 'default_life_months' => 48]);
    $model = AssetModel::query()->create(['asset_category_id' => $this->laptop->id, 'name' => 'Latitude', 'life_months' => 24]);
    $asset = svcLaptop(['asset_tag' => 'LT-1', 'purchase_cost' => 120000, 'purchase_date' => '2025-09-21', 'asset_model_id' => $model->id]);
    $this->assets->assign($asset, $this->employee);

    // Product life (24 months) beats the type's (48): 12 of 24 months used → half the cost.
    expect($this->assets->bookValue($asset->refresh()))->toBe(60000.0)
        ->and($this->assets->bookValue($asset, '2028-01-01'))->toBe(0.0);
    app(SettingsRepository::class)->set('asset.exit.recovery_basis', 'book_value', 'HROne parity');
    expect($this->assets->suggestedRecovery($this->employee))->toBe(60000.0);

    $exits = app(Exits::class);
    $case = $exits->resign($this->employee, 'Relocating', null, $this->employee->user);
    $exits->startClearance($case->refresh());
    $titles = $keeper->notifications()->get()->map(fn ($n) => (string) data_get($n->data, 'title'));
    expect($titles->filter(fn ($t) => str_contains($t, 'Asset clearance')))->toHaveCount(1);

    $stage = $case->clearances()->where('stage', 'asset')->firstOrFail();
    expect(fn () => $exits->clearStage($stage, $this->admin, 'ok'))->toThrow(RuntimeException::class, 'record a recoverable amount');

    app(SettingsRepository::class)->set('asset.exit.recovery_required', false, 'HROne parity');
    expect(fn () => $exits->clearStage($stage->refresh(), $this->admin, ''))->toThrow(RuntimeException::class, 'reason for waiving');
    $cleared = $exits->clearStage($stage->refresh(), $this->admin, 'Laptop kept as a farewell gift (approved)');
    expect($cleared->status)->toBe('cleared')->and((float) $cleared->recoverable_amount)->toBe(0.0);
    $event = AuditEvent::withoutTenancy()->where('module', 'exit')->where('entity_id', (string) $stage->id)->latest('id')->firstOrFail();
    expect($event->metadata['asset_recovery_waived'] ?? null)->toBe(['LT-1']);
});

it('manages sub types, type roles and product details and documents on their screens, for asset staff only', function () {
    Livewire::test(ManageAssetSubtypes::class)->assertOk()
        ->callAction('create', ['asset_category_id' => $this->laptop->id, 'name' => 'Premium series', 'code' => 'PREM', 'allow_duplicate_serial' => false, 'acknowledgement_required' => true, 'status' => 'active'])
        ->assertHasNoActionErrors();
    $premium = AssetSubtype::query()->where('code', 'PREM')->firstOrFail();
    expect($premium->allow_duplicate_serial)->toBeFalse()->and($premium->acknowledgement_required)->toBeTrue();
    Livewire::test(ManageAssetSubtypes::class)->filterTable('asset_category_id', [$this->monitor->id])->assertCanNotSeeTableRecords([$premium])
        ->resetTableFilters()->filterTable('asset_category_id', [$this->laptop->id])->assertCanSeeTableRecords([$premium]);

    $role = Role::factory()->create(['name' => 'Laptop desk']);
    Livewire::test(ManageAssetCategories::class)->filterTable('id', [$this->laptop->id])->assertCanSeeTableRecords([$this->laptop])
        ->callTableAction('edit', $this->laptop, ['owner_role_id' => $role->id, 'fulfilment_days' => 3])->assertHasNoTableActionErrors();
    expect($this->laptop->refresh()->owner_role_id)->toBe($role->id)->and($this->laptop->fulfilment_days)->toBe(3);

    Livewire::test(ManageAssetModels::class)
        ->callAction('create', ['asset_category_id' => $this->laptop->id, 'asset_subtype_id' => $premium->id, 'manufacturer' => 'Dell', 'name' => 'Latitude 9450', 'status' => 'active',
            'description' => 'Executive laptop', 'life_months' => 36, 'deployment_type' => 'hardware',
            'documents' => [UploadedFile::fake()->createWithContent('warranty.pdf', "%PDF-1.4\n%test\n")]])
        ->assertHasNoActionErrors();
    $model = AssetModel::query()->where('name', 'Latitude 9450')->firstOrFail();
    expect($model->asset_subtype_id)->toBe($premium->id)->and($model->life_months)->toBe(36)->and($model->deployment_type)->toBe('hardware')
        ->and($model->documents)->toHaveCount(1)->and($model->documents[0])->toStartWith('tenants/'.$this->tenant->id.'/assets/models/');
    Storage::disk('local')->assertExists($model->documents[0]);

    // Documents open through a signed, authorised, audited link.
    $url = ParityFileController::url('asset-model', $model, 0);
    $this->get($url)->assertOk();
    expect(AuditEvent::withoutTenancy()->where('module', 'assets')->where('entity_id', (string) $model->id)->where('action', 'ATTACHMENT_DOWNLOADED')->exists())->toBeTrue();
    $this->actingAs($this->employee->user);
    $this->get($url)->assertForbidden();
    $this->get(AssetSubtypeResource::getUrl('index'))->assertForbidden();
});
