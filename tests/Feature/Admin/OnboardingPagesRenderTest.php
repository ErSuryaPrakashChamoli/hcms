<?php

use App\Domain\Bgv\Services\Bgv;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\Role;
use App\Domain\Integration\Models\ApiKey;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Onboarding\Models\OnboardingTemplate;
use App\Domain\Onboarding\Services\Onboarding;
use App\Filament\Pages\TaskInbox;
use App\Filament\Resources\ApiKeys\ApiKeyResource;
use App\Filament\Resources\ApiKeys\Pages\ManageApiKeys;
use App\Filament\Resources\BgvCases\BgvCaseResource;
use App\Filament\Resources\DocumentTypes\DocumentTypeResource;
use App\Filament\Resources\DocumentTypes\Pages\ManageDocumentTypes;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Resources\OnboardingPlans\OnboardingPlanResource;
use App\Filament\Resources\OnboardingTemplates\OnboardingTemplateResource;
use App\Filament\Resources\OnboardingTemplates\Pages\EditOnboardingTemplate;
use App\Filament\Resources\OnboardingTemplates\RelationManagers\ItemsRelationManager;
use App\Filament\Widgets\PeopleControlCentre;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->manager = employeeWithUser(null, ['task.view', 'task.act', 'onboarding.act']);
    $this->employee = employeeWithUser($this->manager);
    $this->template = OnboardingTemplate::create(['name' => 'Standard', 'key' => 'standard']);
    $this->template->items()->create(['phase' => 'day_one', 'type' => 'task', 'title' => 'Say hello', 'owner_type' => 'manager']);
    $this->plan = app(Onboarding::class)->start($this->employee, $this->template);
    $this->case = app(Bgv::class)->initiate($this->employee, ['identity'], 'manual', true);
    actAsTenant(null);
});

it('renders onboarding, BGV, document type, API key and 360 pages', function () {
    $this->get(OnboardingTemplateResource::getUrl('index'))->assertOk()->assertSee('Standard');
    $this->get(OnboardingTemplateResource::getUrl('edit', ['record' => $this->template]))->assertOk()->assertSee('Checklist items');
    $this->get(OnboardingPlanResource::getUrl('index'))->assertOk()->assertSee($this->employee->person->display_name);
    $this->get(BgvCaseResource::getUrl('index'))->assertOk();
    $this->get(BgvCaseResource::getUrl('view', ['record' => $this->case]))->assertOk()->assertSee('Identity');
    // The list holds the whole default catalogue (84 types since 2026-11-27, paginated): find PAN card by searching it.
    $this->get(DocumentTypeResource::getUrl('index'))->assertOk();
    Livewire::test(ManageDocumentTypes::class)->searchTable('PAN card')->assertSee('PAN card');
    $this->get(ApiKeyResource::getUrl('index'))->assertOk();
    $this->get('/admin')->assertOk();
    Livewire::test(PeopleControlCentre::class)->assertSee('Open verifications')->assertSee('Onboarding overdue');
    $this->get(EmployeeResource::getUrl('view', ['record' => $this->employee]))->assertOk()->assertSee('Onboarding')->assertSee('Documents')->assertSee('Verification');
});

it('lists checklist items owned by a role or a person without loading each owner on its own', function () {
    // Production 2026-11-20: a template with role- and user-owned items failed (lazy load of ownerRole on each row).
    actAsTenant($this->tenant);
    $role = Role::query()->create(['name' => 'IT desk', 'slug' => 'it-desk-'.uniqid()]);
    $this->template->items()->create(['phase' => 'day_one', 'type' => 'task', 'title' => 'Laptop', 'owner_type' => 'role', 'owner_role_id' => $role->id]);
    $this->template->items()->create(['phase' => 'day_one', 'type' => 'task', 'title' => 'Buddy lunch', 'owner_type' => 'user', 'owner_user_id' => $this->manager->user->id]);

    Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $this->template, 'pageClass' => EditOnboardingTemplate::class])
        ->assertOk()->assertSee('Role: IT desk')->assertSee($this->manager->user->name)->assertSee('Say hello');
});

it('lets the manager complete an onboarding task from the inbox', function () {
    $this->actingAs($this->manager->user);
    actAsTenant($this->tenant);
    $task = $this->plan->tasks->first();

    expect(TaskInbox::getNavigationBadge())->toBe('1');

    Livewire::test(TaskInbox::class)
        ->call('setTab', 'onboarding')
        ->assertSee('Say hello')
        ->callTableAction('complete', $task, data: ['note' => 'Done'])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Task completed');

    expect($this->plan->fresh()->status)->toBe('completed');
});

it('marks a pre-employee as joined and issues an API key from the admin', function () {
    actAsTenant($this->tenant);
    $pre = Employee::factory()->create(['lifecycle_state' => LifecycleState::Preboarding, 'expected_joining_date' => '2026-10-01', 'joining_date' => null]);

    Livewire::test(ViewEmployee::class, ['record' => $pre->getRouteKey()])
        ->callAction('markJoined', data: ['joining_date' => '2026-10-01', 'audit_reason' => 'Reported on time'])
        ->assertHasNoActionErrors()
        ->assertNotified('Joined');
    expect($pre->fresh()->lifecycle_state)->toBe(LifecycleState::Probation)->and($pre->fresh()->joining_date->toDateString())->toBe('2026-10-01');

    Livewire::test(ManageApiKeys::class)
        ->callAction('create', data: ['name' => 'RMS', 'scopes' => ['rms.write']])
        ->assertHasNoActionErrors();
    expect(ApiKey::query()->where('name', 'RMS')->exists())->toBeTrue();
});

it('hides onboarding and BGV administration without permission', function () {
    $this->actingAs(tenantUser($this->tenant, ['employee.view']));
    $this->get(OnboardingTemplateResource::getUrl('index'))->assertForbidden();
    $this->get(BgvCaseResource::getUrl('index'))->assertForbidden();
    $this->get(ApiKeyResource::getUrl('index'))->assertForbidden();
});
