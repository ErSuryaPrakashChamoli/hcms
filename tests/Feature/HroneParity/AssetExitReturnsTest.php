<?php

use App\Domain\Assets\Models\AssetCategory;
use App\Domain\Assets\Models\AssetRequest;
use App\Domain\Assets\Services\Assets;
use App\Domain\Configuration\Services\SetupCatalogue;
use App\Domain\Exit\Services\Exits;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Support\PolicySettingsSchema;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

/* Owner decision 2026-10-29 (AS-B, asset return on exit): return requests are raised by default when an exit is
 * initiated, with configurable exceptions (exit types and asset types, Setup → Asset); no request when the employee holds
 * no company asset; switching asset.exit.return_requests off stops them altogether. */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->assets = app(Assets::class);
    $this->exits = app(Exits::class);
    $this->laptop = AssetCategory::query()->where('code', 'LAPTOP')->firstOrFail();
    $this->monitor = AssetCategory::query()->where('code', 'MONITOR')->firstOrFail();
    $this->employee = activeEmployee(null, ['exit.resign']);
});

function aerHand(AssetCategory $category, string $tag, $employee): void
{
    $asset = app(Assets::class)->receive(['asset_category_id' => $category->id, 'asset_tag' => $tag, 'name' => $category->name.' '.$tag]);
    app(Assets::class)->assign($asset, $employee);
}

function aerReturns(int $employeeId): array
{
    return AssetRequest::query()->where('employee_id', $employeeId)->where('source', 'exit')->where('kind', 'return')->orderBy('id')->get()
        ->map(fn (AssetRequest $r) => $r->asset_category_id)->all();
}

it('raises a return request for every company asset by default, and none when the employee holds no asset', function () {
    $settings = app(SettingsRepository::class);
    expect($settings->get('asset.exit.return_requests'))->toBeTrue()
        ->and($settings->get('asset.exit.return_excluded_exit_types'))->toBe([])
        ->and($settings->get('asset.exit.return_excluded_categories'))->toBe([])
        ->and(collect(config('peopleos_setup.modules.asset.settings'))->pluck('key')->all())->toContain('asset.exit.return_excluded_exit_types', 'asset.exit.return_excluded_categories');

    // Nothing held: no request, the exit goes on as before.
    $empty = activeEmployee(null, ['exit.resign']);
    $case = $this->exits->resign($empty, 'Relocating', null, $empty->user);
    expect($case->status)->not->toBe('withdrawn')->and(AssetRequest::query()->where('employee_id', $empty->id)->count())->toBe(0);

    aerHand($this->laptop, 'LT-1', $this->employee);
    aerHand($this->monitor, 'MN-1', $this->employee);
    $case = $this->exits->resign($this->employee, 'Relocating', null, $this->employee->user);
    $returns = AssetRequest::query()->where('employee_id', $this->employee->id)->where('source', 'exit')->get();
    expect(aerReturns($this->employee->id))->toBe([$this->laptop->id, $this->monitor->id])
        ->and($returns->pluck('status')->unique()->all())->toBe(['approved'])
        ->and($returns->first()->due_on->toDateString())->toBe($case->last_working_day->toDateString());
});

it('leaves out the exit types and asset types listed as exceptions, and raises nothing when switched off', function () {
    $settings = app(SettingsRepository::class);
    $settings->set('asset.exit.return_excluded_exit_types', ['death', 'absconding'], 'Exceptions');
    $settings->set('asset.exit.return_excluded_categories', [(string) $this->monitor->id], 'Monitors stay');

    aerHand($this->laptop, 'LT-2', $this->employee);
    aerHand($this->monitor, 'MN-2', $this->employee);
    $this->exits->resign($this->employee, 'Relocating', null, $this->employee->user);
    expect(aerReturns($this->employee->id))->toBe([$this->laptop->id]); // the monitor's type is an exception

    $deceased = activeEmployee();
    aerHand($this->laptop, 'LT-3', $deceased);
    $this->exits->initiate($deceased, 'death', 'Passed away', now(), now(), 0, $this->admin);
    expect(aerReturns($deceased->id))->toBe([]); // the exit type is an exception

    $settings->set('asset.exit.return_requests', false, 'Collected by hand');
    $leaver = activeEmployee();
    aerHand($this->laptop, 'LT-4', $leaver);
    $this->exits->initiate($leaver, 'termination', 'Misconduct', now(), now(), 0, $this->admin);
    expect(aerReturns($leaver->id))->toBe([]);
});

it('offers the exceptions in Setup → Asset from the exit types and the asset types, and refuses unknown values', function () {
    $catalogue = app(SetupCatalogue::class);
    $policyOptions = (new ReflectionMethod(PolicySettingsSchema::class, 'options'))->invoke(null, ['options_from' => 'exit_types']);
    expect($catalogue->optionsFor(['type' => 'multiselect', 'options_from' => 'exit_types']))->toBe(config('peopleos.exit.types'))
        ->and($policyOptions)->toBe(config('peopleos.exit.types'));

    $changed = $catalogue->save('asset', [
        SetupCatalogue::field('asset.exit.return_excluded_exit_types') => ['death'],
        SetupCatalogue::field('asset.exit.return_excluded_categories') => [$this->monitor->id],
    ], 'Exceptions at exit', $this->admin);
    expect($changed)->toContain('asset.exit.return_excluded_exit_types', 'asset.exit.return_excluded_categories')
        ->and(app(SettingsRepository::class)->get('asset.exit.return_excluded_exit_types'))->toBe(['death'])
        ->and(fn () => $catalogue->save('asset', [SetupCatalogue::field('asset.exit.return_excluded_exit_types') => ['holiday']], 'x', $this->admin))
        ->toThrow(RuntimeException::class, 'does not exist');
});
