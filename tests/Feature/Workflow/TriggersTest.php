<?php

use App\Domain\Configuration\Models\Form;
use App\Domain\Configuration\Models\FormSubmission;
use App\Domain\Configuration\Services\ConfigurationChanges;
use App\Domain\Configuration\Services\Forms;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Services\LifecycleEngine;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Platform\Services\FeatureFlags;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowInstance;

require_once __DIR__.'/WorkflowTestHelpers.php';

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['*']));
    $this->manager = employeeWithUser();
});

it('starts workflows on lifecycle events, honouring start conditions', function () {
    $sales = Department::factory()->create(['name' => 'Sales']);
    [$nodes, $edges] = linear([['id' => 'welcome', 'type' => 'notification', 'name' => 'Welcome', 'config' => ['audience' => [['type' => 'manager']], 'channels' => ['in_app'], 'subject' => 'New joiner {{ employee.name }}', 'body' => 'in {{ employee.department }}']]]);
    publishWorkflow($nodes, $edges, ['name' => 'Sales onboarding', 'key' => 'sales_onboarding', 'trigger_event' => 'employee.joined', 'start_conditions' => [['field' => 'department_id', 'operator' => 'equals', 'value' => $sales->id]]]);
    publishWorkflow($nodes, $edges, ['name' => 'All onboarding', 'key' => 'all_onboarding', 'trigger_event' => 'employee.joined']);

    $company = Company::query()->first();
    $salesHire = app(HireEmployeeAction::class)->handle(['first_name' => 'S', 'last_name' => 'X'], ['joining_date' => '2025-01-01'], ['company_id' => $company->id, 'department_id' => $sales->id], $this->manager->id);
    $otherHire = app(HireEmployeeAction::class)->handle(['first_name' => 'O', 'last_name' => 'X'], ['joining_date' => '2025-01-01'], ['company_id' => $company->id], $this->manager->id);

    expect(WorkflowInstance::query()->where('subject_id', $salesHire->id)->count())->toBe(2)
        ->and(WorkflowInstance::query()->where('subject_id', $otherHire->id)->count())->toBe(1)
        ->and(WorkflowInstance::query()->where('subject_id', $otherHire->id)->first()->workflow->key)->toBe('all_onboarding')
        ->and(WorkflowInstance::query()->first()->contextValue('trigger'))->toBe('employee.joined');
});

it('starts workflows on form submissions with access to the answers', function () {
    $form = Form::create(['name' => 'Travel', 'key' => 'travel']);
    app(Forms::class)->draft($form)->update(['fields' => [['key' => 'amount', 'label' => 'Amount', 'type' => 'number', 'required' => true]]]);
    app(Forms::class)->publish($form);

    $nodes = [
        ['id' => 'start', 'type' => 'start', 'name' => 'Start', 'config' => []],
        ['id' => 'big', 'type' => 'condition', 'name' => 'Big?', 'config' => ['conditions' => [['field' => 'data.amount', 'operator' => 'gte', 'value' => 1000]]]],
        ['id' => 'appr', 'type' => 'approval', 'name' => 'Approve', 'config' => ['mode' => 'single', 'approver' => ['type' => 'user', 'user_id' => auth()->id()]]],
        ['id' => 'end', 'type' => 'end', 'name' => 'End', 'config' => []],
    ];
    $edges = [['from' => 'start', 'to' => 'big', 'label' => null], ['from' => 'big', 'to' => 'appr', 'label' => 'yes'], ['from' => 'big', 'to' => 'end', 'label' => 'no'], ['from' => 'appr', 'to' => 'end', 'label' => null]];
    publishWorkflow($nodes, $edges, ['trigger_event' => 'form.submitted', 'subject_type' => FormSubmission::class]);

    $small = app(Forms::class)->submit($form, ['amount' => 100]);
    $large = app(Forms::class)->submit($form, ['amount' => 5000]);

    expect(WorkflowInstance::query()->where('subject_id', $small->id)->first()->status->value)->toBe('completed')
        ->and(WorkflowInstance::query()->where('subject_id', $large->id)->first()->current_node_id)->toBe('appr');
});

it('starts workflows when a configuration change needs approval', function () {
    app(FeatureFlags::class)->set('configuration.approval', true);
    [$nodes, $edges] = linear([['id' => 'review', 'type' => 'task', 'name' => 'Review change #{{ subject.id }}', 'config' => ['assignee' => ['type' => 'user', 'user_id' => auth()->id()]]]]);
    publishWorkflow($nodes, $edges, ['trigger_event' => 'configuration.change.proposed']);

    $company = Company::query()->first();
    $change = app(ConfigurationChanges::class)->propose($company, ['name' => 'Renamed'], 'x');

    $instance = WorkflowInstance::query()->where('subject_type', $change->getMorphClass())->first();
    expect($instance)->not->toBeNull()
        ->and($instance->tasks->first()->title)->toBe("Review change #{$change->id}");
});

it('does not start inactive or unpublished workflows', function () {
    [$nodes, $edges] = linear([]);
    $inactive = publishWorkflow($nodes, $edges, ['trigger_event' => 'employee.confirmed']);
    $inactive->update(['status' => 'inactive']);
    Workflow::create(['name' => 'Draft only', 'key' => 'draft_only', 'trigger_event' => 'employee.confirmed']);

    $employee = employeeWithUser($this->manager);
    app(LifecycleEngine::class)->transition($employee, LifecycleState::Confirmed);

    expect(WorkflowInstance::query()->count())->toBe(0);
});
