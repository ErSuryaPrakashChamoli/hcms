<?php

use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetCategory;
use App\Domain\Assets\Models\AssetModel;
use App\Domain\Assets\Models\AssetRequest;
use App\Domain\Assets\Models\AssetSubtype;
use App\Domain\Assets\Services\AssetEntitlements;
use App\Domain\Assets\Services\AssetRequests;
use App\Domain\Assets\Services\Assets;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Configuration\Services\SetupCatalogue;
use App\Domain\Exit\Services\Exits;
use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Services\ApprovalDecisions;
use App\Domain\Identity\Models\Role;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Domain\Workflow\Models\WorkflowTask;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Filament\Pages\SetupCentre;
use App\Filament\Resources\AssetRequests\AssetRequestResource;
use App\Filament\Resources\AssetRequests\Pages\ListAssetRequests;
use App\Filament\Support\PolicySettingsSchema;
use Illuminate\Support\Str;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

/* HROne setup parity AS-B — asset entitlement policy and asset requests: new / replacement / return requests with the
 * approval the settings ask for (workflows or the Approval Center), fulfilment by the asset type's owner role, who may
 * choose the sub type and product, mapped requests and HR assignment, joiners' entitled assets and exit returns. */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->assets = app(Assets::class);
    $this->requests = app(AssetRequests::class);
    $this->laptop = AssetCategory::query()->where('code', 'LAPTOP')->firstOrFail();
    $this->monitor = AssetCategory::query()->where('code', 'MONITOR')->firstOrFail();
    $this->premium = AssetSubtype::query()->create(['asset_category_id' => $this->laptop->id, 'name' => 'Premium series', 'code' => 'PREM']);
    $this->latitude = AssetModel::query()->create(['asset_category_id' => $this->laptop->id, 'asset_subtype_id' => $this->premium->id, 'name' => 'Latitude 9450']);
    $this->itStore = Role::factory()->create(['name' => 'IT store']);
    $this->keeper = tenantUser($this->tenant, ['asset.view']);
    $this->itStore->users()->attach($this->keeper);
    $this->laptop->update(['owner_role_id' => $this->itStore->id, 'fulfilment_days' => 5]);
    $this->manager = activeEmployee(null, ['asset.request', 'asset.own', 'task.view']);
    $this->employee = activeEmployee($this->manager, ['asset.request', 'asset.own', 'task.view', 'exit.resign']);
});

function asrPolicy(array $settings, int $priority = 1000): Policy
{
    $policy = Policy::create(['type' => 'asset', 'name' => 'Assets '.uniqid(), 'code' => 'ASR_'.strtoupper(uniqid())]);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => 'asset', 'policy_id' => $policy->id, 'name' => 'Everyone', 'conditions' => [], 'priority' => $priority]);

    return $policy;
}

function asrAsset(string $tag, AssetCategory $category, array $attributes = []): Asset
{
    return app(Assets::class)->receive($attributes + ['asset_category_id' => $category->id, 'asset_tag' => $tag, 'name' => $category->name.' '.$tag]);
}

// Owner decision 2026-10-29: return requests are raised at exit by default (this test asserted none before the decision).
it('keeps today\'s behaviour by default: no requests at joining, any asset assigned, the settings and the policy type declared; returns at exit by default', function () {
    $settings = app(SettingsRepository::class);
    expect($settings->get('asset.onboarding.due_days'))->toBe(7)->and($settings->get('asset.exit.return_requests'))->toBeTrue()
        ->and($settings->get('asset.exit.due_days'))->toBe(0)->and($settings->get('asset.request.workflow_for'))->toBe('new')
        ->and($settings->get('asset.request.subtype_visible_to'))->toBe(['employee', 'manager', 'hr'])->and($settings->get('asset.request.model_visible_to'))->toBe(['hr'])
        ->and(config('peopleos_setup.modules.asset.policy_types'))->toBe(['asset'])
        ->and(config('peopleos.roles.employee.permissions'))->toContain('asset.request')->and(config('peopleos.roles.manager.permissions'))->toContain('asset.request')
        ->and(array_column(PolicySettingsSchema::steps('asset'), 'label'))->toContain('Asset assignment', 'Asset requests', 'Assignment by HR')
        ->and(app(SetupCatalogue::class)->optionsFor(['type' => 'select', 'options_from' => 'asset_subtypes']))->toBe([$this->premium->id => 'Premium series']);

    $joiner = employeeWithUser(null, ['asset.request']);
    $this->assets->assign(asrAsset('LT-1', $this->laptop), $this->employee);
    app(Exits::class)->resign($this->employee, 'Relocating', null, $this->employee->user);
    expect(AssetRequest::query()->where('employee_id', $joiner->id)->count())->toBe(0)->and($joiner->id)->not->toBeNull()
        ->and(AssetRequest::query()->where('employee_id', $this->employee->id)->where('source', 'exit')->pluck('kind')->all())->toBe(['return'])
        ->and(app(AssetEntitlements::class)->for($this->employee))->toMatchArray(['policy_version_id' => null, 'entitlements' => [], 'request_scope' => 'any', 'hr_assign_scope' => 'any']);
});

it('waits for approval of a new asset, which the manager approves in the Approval Center, and the owner role fulfils', function () {
    $request = $this->requests->request($this->employee, ['kind' => 'new', 'asset_category_id' => $this->laptop->id, 'asset_subtype_id' => $this->premium->id,
        'asset_model_id' => $this->latitude->id, 'reason' => 'Mine is slow', 'needed_by' => '2026-09-30'], $this->employee->user);
    expect($request->number)->toStartWith('AR-2026-')->and($request->status)->toBe('requested')->and($request->source)->toBe('employee')
        ->and($request->asset_subtype_id)->toBe($this->premium->id)->and($request->asset_model_id)->toBeNull() // employees may not choose the product by default
        ->and($request->owner_role_id)->toBe($this->itStore->id)->and($request->due_on)->toBeNull();

    expect(fn () => $this->requests->approve($request, null, $this->employee->user))->toThrow(RuntimeException::class, 'You cannot decide or fulfil your own asset request.')
        ->and(collect(app(ApprovalCenter::class)->pending($this->employee->user))->where('type', 'asset_request'))->toHaveCount(0);
    $item = collect(app(ApprovalCenter::class)->pending($this->manager->user))->firstWhere('type', 'asset_request');
    expect($item)->not->toBeNull()->and($item->title)->toContain('Laptop · Premium series')->and($item->impact)->toContain('within 5 day(s)');
    expect(app(ApprovalDecisions::class)->decide($this->manager->user, $item->id, 'approve', 'Fine'))->toBe('Asset request approved');
    $request->refresh();
    expect($request->status)->toBe('approved')->and($request->due_on->toDateString())->toBe('2026-09-26')->and($request->decided_by)->toBe($this->manager->user->id);

    // The owner role (without asset.assign) fulfils it with an asset of the type and sub type requested.
    $wrong = asrAsset('LT-STD', $this->laptop);
    $right = asrAsset('LT-PREM', $this->laptop, ['asset_subtype_id' => $this->premium->id]);
    expect(fn () => $this->requests->fulfil($request, null, [], $this->manager->user))->toThrow(RuntimeException::class, 'You cannot act on this asset request.')
        ->and(fn () => $this->requests->fulfil($request, $wrong, [], $this->keeper))->toThrow(RuntimeException::class, 'is not the asset requested');
    $this->requests->fulfil($request, $right, ['note' => 'Handed over'], $this->keeper);
    expect($request->refresh()->status)->toBe('fulfilled')->and($request->fulfilled_asset_id)->toBe($right->id)
        ->and($right->refresh()->custodian_id)->toBe($this->employee->id)->and($right->status)->toBe('assigned');
});

it('goes straight to fulfilment without approval as asset.request.workflow_for says; returns and replacements of held assets', function () {
    $held = asrAsset('LT-1', $this->laptop);
    $this->assets->assign($held, $this->employee);

    $return = $this->requests->request($this->employee, ['kind' => 'return', 'asset_id' => $held->id, 'reason' => 'Moving to a desktop'], $this->employee->user);
    expect($return->status)->toBe('approved')->and($return->asset_category_id)->toBe($this->laptop->id)->and($return->due_on->toDateString())->toBe('2026-09-26')
        ->and(fn () => $this->requests->request($this->employee, ['kind' => 'return', 'asset_id' => $held->id, 'reason' => 'Again'], $this->employee->user))->toThrow(RuntimeException::class, 'already open')
        ->and(fn () => $this->requests->request($this->employee, ['kind' => 'return', 'asset_id' => asrAsset('LT-X', $this->laptop)->id, 'reason' => 'x'], $this->employee->user))->toThrow(RuntimeException::class, 'Choose an asset the employee holds.');
    $this->requests->cancel($return, 'Keeping it', $this->employee->user);
    expect($return->refresh()->status)->toBe('cancelled');

    app(SettingsRepository::class)->set('asset.request.workflow_for', 'none', 'No approval');
    $replacement = $this->requests->request($this->employee, ['kind' => 'replacement', 'asset_id' => $held->id, 'reason' => 'Broken hinge'], $this->employee->user);
    expect($replacement->status)->toBe('approved');
    $new = asrAsset('LT-2', $this->laptop);
    $this->requests->fulfil($replacement, $new, ['condition' => 'damaged'], $this->admin);
    expect($held->refresh()->status)->toBe('in_stock')->and($held->condition)->toBe('damaged')->and($new->refresh()->custodian_id)->toBe($this->employee->id)
        ->and($replacement->refresh()->fulfilled_asset_id)->toBe($new->id);

    app(SettingsRepository::class)->set('asset.request.workflow_for', 'both', 'Everything approved');
    $declined = $this->requests->request($this->employee, ['kind' => 'return', 'asset_id' => $new->id, 'reason' => 'Leaving the team'], $this->employee->user);
    expect($declined->status)->toBe('requested');
    $this->requests->approve($declined, null, $this->manager->user);
    expect(fn () => $this->requests->reject($declined->refresh(), 'Not now', $this->manager->user))->toThrow(RuntimeException::class, 'You cannot act on this asset request.');
    $this->requests->reject($declined, 'Keep it until the project ends', $this->keeper);
    expect($declined->refresh()->status)->toBe('rejected');
});

it('lets each audience choose the sub type and product only as the settings allow, and refuses what the policy does not map', function () {
    app(SettingsRepository::class)->set('asset.request.subtype_visible_to', ['hr'], 'HR only');
    app(SettingsRepository::class)->set('asset.request.model_visible_to', ['employee', 'manager', 'hr'], 'Everyone');
    $own = $this->requests->request($this->employee, ['asset_category_id' => $this->laptop->id, 'asset_subtype_id' => $this->premium->id, 'asset_model_id' => $this->latitude->id], $this->employee->user);
    expect($own->asset_subtype_id)->toBeNull()->and($own->asset_model_id)->toBe($this->latitude->id);
    $byManager = $this->requests->request($this->employee, ['asset_category_id' => $this->laptop->id, 'asset_subtype_id' => $this->premium->id], $this->manager->user);
    expect($byManager->source)->toBe('manager')->and($byManager->asset_subtype_id)->toBeNull()->and($byManager->requested_by)->toBe($this->manager->user->id);
    $byHr = $this->requests->request($this->employee, ['asset_category_id' => $this->laptop->id, 'asset_subtype_id' => $this->premium->id], $this->admin);
    expect($byHr->source)->toBe('hr')->and($byHr->asset_subtype_id)->toBe($this->premium->id)
        ->and(fn () => $this->requests->request($this->employee, ['asset_category_id' => $this->monitor->id, 'asset_model_id' => $this->latitude->id], $this->admin))->toThrow(RuntimeException::class, 'Choose a product of Monitor.');

    $stranger = activeEmployee(null, ['asset.request']);
    expect(fn () => $this->requests->request($this->employee, ['asset_category_id' => $this->laptop->id], $stranger->user))->toThrow(RuntimeException::class, 'You cannot request assets for this employee.');
    $noPermission = activeEmployee(null, ['asset.own']);
    expect(fn () => $this->requests->request($noPermission, ['asset_category_id' => $this->laptop->id], $noPermission->user))->toThrow(RuntimeException::class, 'You cannot request assets for this employee.');

    asrPolicy(['entitlements' => [['category' => $this->laptop->id, 'subtype' => $this->premium->id]], 'request_scope' => 'mapped']);
    expect(fn () => $this->requests->request($this->employee, ['asset_category_id' => $this->monitor->id], $this->employee->user))->toThrow(RuntimeException::class, 'Monitor is not among the assets your asset policy maps to you')
        ->and($this->requests->request($this->employee, ['asset_category_id' => $this->laptop->id], $this->employee->user)->status)->toBe('requested')
        ->and($this->requests->request($this->employee, ['asset_category_id' => $this->monitor->id], $this->admin)->status)->toBe('requested'); // HR is not limited
});

it('checks HR assignments against the entitlement: refused when mapped, recorded when warned, free by default', function () {
    $monitor = asrAsset('MN-1', $this->monitor);
    asrPolicy(['entitlements' => [['category' => $this->laptop->id]], 'hr_assign_scope' => 'mapped']);
    expect(fn () => $this->assets->assign($monitor, $this->employee))->toThrow(RuntimeException::class, 'HR may assign only mapped assets');
    $this->assets->assign(asrAsset('LT-1', $this->laptop), $this->employee);

    asrPolicy(['entitlements' => [['category' => $this->laptop->id]], 'hr_assign_scope' => 'warn'], 500);
    $assignment = $this->assets->assign($monitor->refresh(), $this->employee, null, null, null, null, $this->admin);
    $event = AuditEvent::withoutTenancy()->where('module', 'assets')->where('entity_id', (string) $assignment->id)->get()->first(fn ($e) => ($e->metadata['outside_entitlement'] ?? false) === true);
    expect($event)->not->toBeNull()->and($event->reason)->toContain('MN-1');
});

it('raises a joiner\'s entitled assets once, due after joining, and returns at exit (cancelled on withdrawal)', function () {
    asrPolicy(['entitlements' => [['category' => $this->laptop->id, 'subtype' => $this->premium->id], ['category' => $this->monitor->id, 'quantity' => 2]]]);
    $joiner = employeeWithUser(null, ['asset.request']);
    $raised = AssetRequest::query()->where('employee_id', $joiner->id)->orderBy('id')->get();
    expect($raised)->toHaveCount(3)->and($raised->pluck('source')->unique()->all())->toBe(['policy'])->and($raised->pluck('status')->unique()->all())->toBe(['approved'])
        ->and($raised->first()->due_on->toDateString())->toBe('2025-01-08')->and($raised->first()->asset_subtype_id)->toBe($this->premium->id)
        ->and($raised->first()->owner_role_id)->toBe($this->itStore->id)->and($raised->last()->owner_role_id)->toBe(Role::query()->where('slug', 'asset-admin')->value('id'))
        ->and(app(AssetEntitlements::class)->provisionJoiner($joiner))->toBe(0); // raised once
    expect($this->keeper->notifications()->get()->filter(fn ($n) => str_contains((string) data_get($n->data, 'title'), 'to fulfil')))->toHaveCount(1);

    app(SettingsRepository::class)->set('asset.exit.return_requests', true, 'Collect at exit');
    app(SettingsRepository::class)->set('asset.exit.due_days', 2, 'Two days before');
    $held = asrAsset('LT-9', $this->laptop);
    $this->assets->assign($held, $this->employee);
    $exits = app(Exits::class);
    $case = $exits->resign($this->employee, 'Relocating', null, $this->employee->user);
    $return = AssetRequest::query()->where('employee_id', $this->employee->id)->where('kind', 'return')->sole();
    expect($return->source)->toBe('exit')->and($return->asset_id)->toBe($held->id)->and($return->status)->toBe('approved')
        ->and($return->due_on->toDateString())->toBe($case->last_working_day->copy()->subDays(2)->toDateString());
    $exits->withdraw($case->refresh(), 'Staying on', $this->admin);
    expect($return->refresh()->status)->toBe('cancelled');
});

it('lets a workflow on asset.requested decide the request instead of the Approval Center', function () {
    [$nodes, $edges] = linear([['id' => 'appr', 'type' => 'approval', 'name' => 'IT head', 'config' => ['mode' => 'single', 'approver' => ['type' => 'user', 'user_id' => $this->admin->id]]]]);
    publishWorkflow($nodes, $edges, ['name' => 'Asset approval', 'key' => 'asset_approval', 'trigger_event' => 'asset.requested']);
    $request = $this->requests->request($this->employee, ['asset_category_id' => $this->laptop->id, 'reason' => 'New hire kit'], $this->employee->user);
    expect(collect(app(ApprovalCenter::class)->pending($this->manager->user))->where('type', 'asset_request'))->toHaveCount(0);
    $task = WorkflowTask::query()->whereIn('workflow_instance_id', WorkflowInstance::query()->where('subject_id', $request->id)->where('subject_type', $request->getMorphClass())->pluck('id'))->where('status', 'pending')->sole();
    app(WorkflowEngine::class)->completeTask($task, 'rejected', 'Use a spare', $this->admin);
    expect($request->refresh()->status)->toBe('rejected')->and($request->decided_by)->toBe($this->admin->id);
});

it('shows My asset requests to employees, the queue to the asset team, and lists the asset settings in Setup', function () {
    $this->actingAs($this->employee->user);
    expect(AssetRequestResource::getNavigationLabel())->toBe('My asset requests')->and(AssetRequestResource::getNavigationGroup())->toBe('Me');
    Livewire::test(ListAssetRequests::class)->assertOk()
        ->callAction('requestAsset', ['kind' => 'new', 'asset_category_id' => $this->laptop->id, 'reason' => 'Second screen'])->assertHasNoActionErrors();
    $request = AssetRequest::query()->sole();
    expect($request->requested_by)->toBe($this->employee->user->id)->and($request->source)->toBe('employee');
    Livewire::test(ListAssetRequests::class)->assertCanSeeTableRecords([$request]);

    $other = $this->requests->request($this->manager, ['asset_category_id' => $this->monitor->id], $this->admin);
    Livewire::test(ListAssetRequests::class)->assertCanNotSeeTableRecords([$other]);

    $this->actingAs($this->admin);
    expect(AssetRequestResource::getNavigationLabel())->toBe('Asset requests');
    $this->get(AssetRequestResource::getUrl('index'))->assertOk();
    Livewire::test(SetupCentre::class)->call('selectModule', 'asset')->assertOk()->assertSee('Which asset requests follow a workflow')->assertSee('Asset entitlement policy');
    expect(Str::of(json_encode(config('peopleos.permissions.asset')))->contains('asset.request'))->toBeTrue();
});
