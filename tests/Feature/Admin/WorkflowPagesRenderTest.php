<?php

use App\Domain\Notifications\Models\NotificationRule;
use App\Domain\Notifications\Models\NotificationTemplate;
use App\Domain\Workflow\Enums\InstanceStatus;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Domain\Workflow\Services\Workflows;
use App\Filament\Pages\TaskInbox;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\NotificationDeliveries\NotificationDeliveryResource;
use App\Filament\Resources\NotificationRules\NotificationRuleResource;
use App\Filament\Resources\NotificationTemplates\NotificationTemplateResource;
use App\Filament\Resources\WorkflowInstances\Pages\ViewWorkflowInstance;
use App\Filament\Resources\WorkflowInstances\WorkflowInstanceResource;
use App\Filament\Resources\Workflows\Pages\EditWorkflow;
use App\Filament\Resources\Workflows\WorkflowResource;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->manager = employeeWithUser();
    $this->employee = employeeWithUser($this->manager);

    [$nodes, $edges] = linear([['id' => 'approve', 'type' => 'approval', 'name' => 'Manager approval', 'config' => ['mode' => 'single', 'approver' => ['type' => 'manager']]]]);
    $this->workflow = publishWorkflow($nodes, $edges, ['name' => 'Confirmation approval', 'key' => 'confirmation']);
    $this->instance = app(WorkflowEngine::class)->start($this->workflow, $this->employee);

    $template = NotificationTemplate::create(['key' => 'welcome', 'name' => 'Welcome', 'subject' => 'Hi', 'body' => 'There']);
    NotificationRule::create(['name' => 'Welcome rule', 'event' => 'employee.joined', 'audience' => [['type' => 'subject']], 'channels' => ['in_app'], 'notification_template_id' => $template->id]);
    actAsTenant(null);
});

it('renders workflow, run, inbox and notification pages', function () {
    $this->get(WorkflowResource::getUrl('index'))->assertOk()->assertSee('Confirmation approval');
    $this->get(WorkflowResource::getUrl('create'))->assertOk();
    $this->get(WorkflowResource::getUrl('edit', ['record' => $this->workflow]))->assertOk()->assertSee('Versions')->assertSee('Runs');
    $this->get(WorkflowInstanceResource::getUrl('index'))->assertOk()->assertSee('Confirmation approval');
    $this->get(WorkflowInstanceResource::getUrl('view', ['record' => $this->instance]))->assertOk()->assertSee('Manager approval')->assertSee('Run log');
    $this->get(TaskInbox::getUrl())->assertOk()->assertSee('Nothing needs your attention');
    $this->get(NotificationTemplateResource::getUrl('index'))->assertOk()->assertSee('Welcome');
    $this->get(NotificationRuleResource::getUrl('index'))->assertOk()->assertSee('Welcome rule');
    $this->get(NotificationDeliveryResource::getUrl('index'))->assertOk();
    $this->get(EmployeeResource::getUrl('view', ['record' => $this->employee]))->assertOk()->assertSee('Workflows');
});

it('lets the assignee approve from the inbox and shows the badge count', function () {
    $this->actingAs($this->manager->user);
    actAsTenant($this->tenant);

    expect(TaskInbox::getNavigationBadge())->toBe('1');
    $this->get(TaskInbox::getUrl())->assertOk()->assertSee('Manager approval');

    Livewire::test(TaskInbox::class)
        ->callTableAction('approve', $this->instance->tasks->first(), data: ['note' => 'OK'])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Approved');

    expect($this->instance->fresh()->status)->toBe(InstanceStatus::Completed)
        ->and(TaskInbox::getNavigationBadge())->toBeNull();

    // Someone else cannot act on it, even with the permission.
    $this->actingAs($this->employee->user);
    $this->get(TaskInbox::getUrl())->assertOk()->assertDontSee('Manager approval');
});

it('cancels a run from its page and blocks publishing an invalid draft', function () {
    actAsTenant($this->tenant);

    Livewire::test(ViewWorkflowInstance::class, ['record' => $this->instance->getRouteKey()])
        ->callAction('cancel', data: ['reason' => 'Withdrawn'])
        ->assertNotified('Run cancelled');
    expect($this->instance->fresh()->status)->toBe(InstanceStatus::Cancelled);

    $draft = app(Workflows::class)->draft($this->workflow, ['nodes' => [['id' => 'start', 'type' => 'start', 'name' => 'S', 'config' => []]], 'edges' => []]);
    Livewire::test(EditWorkflow::class, ['record' => $this->workflow->getRouteKey()])
        ->callAction('publish')
        ->assertNotified('Cannot publish');
    expect($this->workflow->published()->first()->version)->toBe(1);
});

it('hides workflow administration from users without permission', function () {
    $this->actingAs(tenantUser($this->tenant, ['employee.view']));
    $this->get(WorkflowResource::getUrl('index'))->assertForbidden();
    $this->get(TaskInbox::getUrl())->assertForbidden();
});
