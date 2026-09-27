<?php

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\Role;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Workflow\Enums\InstanceStatus;
use App\Domain\Workflow\Enums\TaskStatus;
use App\Domain\Workflow\Exceptions\WorkflowException;
use App\Domain\Workflow\Jobs\SendWebhook;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowTask;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Domain\Workflow\Services\Workflows;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/WorkflowTestHelpers.php';

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->engine = app(WorkflowEngine::class);
    $this->manager = employeeWithUser();
    $this->employee = employeeWithUser($this->manager);
});

it('runs a manager approval end to end', function () {
    [$nodes, $edges] = linear([['id' => 'approve', 'type' => 'approval', 'name' => 'Manager approval', 'config' => ['mode' => 'single', 'approver' => ['type' => 'manager'], 'title' => 'Approve for {{ employee.name }}', 'sla_hours' => 24]]]);
    $workflow = publishWorkflow($nodes, $edges);

    $instance = $this->engine->start($workflow, $this->employee, ['amount' => 500]);

    expect($instance->status)->toBe(InstanceStatus::Running)
        ->and($instance->current_node_id)->toBe('approve')
        ->and($instance->tasks)->toHaveCount(1);

    $task = $instance->tasks->first();
    expect($task->assignee_id)->toBe($this->manager->user_id)
        ->and($task->title)->toBe('Approve for '.$this->employee->person->display_name)
        ->and($task->due_at->diffInHours($task->created_at, true))->toEqual(24.0)
        ->and(NotificationDelivery::query()->where('user_id', $this->manager->user_id)->where('event', 'workflow.task.assigned')->exists())->toBeTrue();

    expect(fn () => $this->engine->completeTask($task, 'approved', null, $this->employee->user))->toThrow(WorkflowException::class, 'not assigned');

    $this->engine->completeTask($task, 'approved', 'Fine by me', $this->manager->user);

    $instance->refresh();
    expect($instance->status)->toBe(InstanceStatus::Completed)
        ->and($instance->outcome)->toBe('approved')
        ->and($instance->actions()->pluck('action')->all())->toContain('started', 'task.created', 'task.approved', 'completed')
        ->and($instance->contextValue('last_note'))->toBe('Fine by me');
});

it('routes rejections to the rejected edge or straight to the end', function () {
    [$nodes, $edges] = linear([['id' => 'approve', 'type' => 'approval', 'name' => 'Approve', 'config' => ['mode' => 'single', 'approver' => ['type' => 'manager']]]]);
    $workflow = publishWorkflow($nodes, $edges);

    $instance = $this->engine->start($workflow, $this->employee);
    $this->engine->completeTask($instance->tasks->first(), 'rejected', 'No', $this->manager->user);

    expect($instance->refresh()->outcome)->toBe('rejected')->and($instance->status)->toBe(InstanceStatus::Completed);
});

it('branches on conditions using employee dimensions and context', function () {
    $nodes = [
        ['id' => 'start', 'type' => 'start', 'name' => 'Start', 'config' => []],
        ['id' => 'big', 'type' => 'condition', 'name' => 'Large amount?', 'config' => ['match' => 'all', 'conditions' => [['field' => 'amount', 'operator' => 'greater_than', 'value' => 1000]]]],
        ['id' => 'set_high', 'type' => 'automation', 'name' => 'Flag', 'config' => ['set' => ['work_phone' => 'HIGH-{{ context.amount }}']]],
        ['id' => 'set_low', 'type' => 'automation', 'name' => 'Flag', 'config' => ['set' => ['work_phone' => 'LOW']]],
        ['id' => 'end', 'type' => 'end', 'name' => 'End', 'config' => []],
    ];
    $edges = [
        ['from' => 'start', 'to' => 'big', 'label' => null],
        ['from' => 'big', 'to' => 'set_high', 'label' => 'yes'],
        ['from' => 'big', 'to' => 'set_low', 'label' => 'no'],
        ['from' => 'set_high', 'to' => 'end', 'label' => null],
        ['from' => 'set_low', 'to' => 'end', 'label' => null],
    ];
    $workflow = publishWorkflow($nodes, $edges);

    $this->engine->start($workflow, $this->employee, ['amount' => 5000]);
    expect($this->employee->fresh()->work_phone)->toBe('HIGH-5000');

    $this->engine->start($workflow, $this->employee, ['amount' => 10]);
    expect($this->employee->fresh()->work_phone)->toBe('LOW')
        ->and($this->employee->auditEvents()->where('action', 'UPDATE')->latest('occurred_at')->value('reason'))->toContain('Workflow: Test flow');
});

it('supports sequential, parallel and majority approvals', function () {
    $a = employeeWithUser();
    $b = employeeWithUser();
    $c = employeeWithUser();
    $specs = [['type' => 'user', 'user_id' => $a->user_id], ['type' => 'user', 'user_id' => $b->user_id], ['type' => 'user', 'user_id' => $c->user_id]];

    // Sequential: one task at a time.
    [$nodes, $edges] = linear([['id' => 'seq', 'type' => 'approval', 'name' => 'Seq', 'config' => ['mode' => 'sequential', 'approvers' => $specs]]]);
    $instance = $this->engine->start(publishWorkflow($nodes, $edges), $this->employee);
    expect($instance->tasks)->toHaveCount(1)->and($instance->tasks->first()->assignee_id)->toBe($a->user_id);
    $this->engine->completeTask($instance->tasks->first(), 'approved', null, $a->user);
    expect($instance->tasks()->count())->toBe(2)->and($instance->tasks()->reorder('id', 'desc')->first()->assignee_id)->toBe($b->user_id);
    $this->engine->completeTask($instance->tasks()->reorder('id', 'desc')->first(), 'rejected', 'no', $b->user);
    expect($instance->refresh()->outcome)->toBe('rejected')->and($instance->tasks()->count())->toBe(2);

    // Parallel: everyone at once, any rejection ends it.
    [$nodes, $edges] = linear([['id' => 'par', 'type' => 'approval', 'name' => 'Par', 'config' => ['mode' => 'parallel', 'approvers' => $specs]]]);
    $instance = $this->engine->start(publishWorkflow($nodes, $edges), $this->employee);
    expect($instance->tasks)->toHaveCount(3);
    $this->engine->completeTask($instance->tasks[0], 'approved', null, $a->user);
    expect($instance->refresh()->status)->toBe(InstanceStatus::Running);
    $this->engine->completeTask($instance->tasks[1], 'approved', null, $b->user);
    $this->engine->completeTask($instance->tasks[2], 'approved', null, $c->user);
    expect($instance->refresh()->outcome)->toBe('approved');

    // Majority: two of three decide, the third is skipped.
    [$nodes, $edges] = linear([['id' => 'maj', 'type' => 'approval', 'name' => 'Maj', 'config' => ['mode' => 'majority', 'approvers' => $specs]]]);
    $instance = $this->engine->start(publishWorkflow($nodes, $edges), $this->employee);
    $this->engine->completeTask($instance->tasks[0], 'approved', null, $a->user);
    $this->engine->completeTask($instance->tasks[1], 'approved', null, $b->user);
    expect($instance->refresh()->outcome)->toBe('approved')
        ->and($instance->tasks[2]->fresh()->status)->toBe(TaskStatus::Skipped);
});

it('assigns approvals to a role that any holder can act on', function () {
    $role = Role::query()->where('slug', 'hr-manager')->first();
    $hrManager = tenantUser($this->tenant, []);
    $hrManager->roles()->attach($role);

    [$nodes, $edges] = linear([['id' => 'hr', 'type' => 'approval', 'name' => 'HR approval', 'config' => ['mode' => 'single', 'approver' => ['type' => 'role', 'role_id' => $role->id]]]]);
    $instance = $this->engine->start(publishWorkflow($nodes, $edges), $this->employee);
    $task = $instance->tasks->first();

    expect($task->assignee_role_id)->toBe($role->id)
        ->and($task->isActionableBy($hrManager))->toBeTrue()
        ->and($task->isActionableBy($this->employee->user))->toBeFalse()
        ->and(WorkflowTask::query()->actionableBy($hrManager)->count())->toBe(1);

    $this->engine->completeTask($task, 'approved', null, $hrManager);
    expect($instance->refresh()->outcome)->toBe('approved');
});

it('creates to-do tasks, waits for time, calls webhooks and sends notifications', function () {
    Queue::fake([SendWebhook::class]);

    $nodes = [
        ['id' => 'start', 'type' => 'start', 'name' => 'Start', 'config' => []],
        ['id' => 'todo', 'type' => 'task', 'name' => 'Prepare laptop', 'config' => ['assignee' => ['type' => 'user', 'user_id' => $this->hr->id], 'instructions' => 'For {{ employee.name }}']],
        ['id' => 'wait', 'type' => 'wait', 'name' => 'Wait a day', 'config' => ['hours' => 24]],
        ['id' => 'hook', 'type' => 'webhook', 'name' => 'Tell IT', 'config' => ['url' => 'https://it.example.test/hooks/{{ employee.code }}', 'method' => 'POST']],
        ['id' => 'notify', 'type' => 'notification', 'name' => 'Welcome', 'config' => ['audience' => [['type' => 'subject'], ['type' => 'manager']], 'channels' => ['in_app'], 'subject' => 'Welcome {{ employee.first_name }}', 'body' => 'Run {{ workflow.run }}']],
        ['id' => 'end', 'type' => 'end', 'name' => 'End', 'config' => []],
    ];
    $edges = [];
    foreach (['start', 'todo', 'wait', 'hook', 'notify'] as $i => $from) {
        $edges[] = ['from' => $from, 'to' => ['todo', 'wait', 'hook', 'notify', 'end'][$i], 'label' => null];
    }
    $instance = $this->engine->start(publishWorkflow($nodes, $edges), $this->employee);

    $task = $instance->tasks->first();
    expect($task->type)->toBe('task')->and($task->instructions)->toBe('For '.$this->employee->person->display_name);

    $this->engine->completeTask($task, 'completed', 'Done', $this->hr);
    $instance->refresh();
    expect($instance->status)->toBe(InstanceStatus::Waiting)->and($instance->current_node_id)->toBe('wait')->and($instance->wake_at)->not->toBeNull();

    expect($this->engine->tick())->toBe(0);
    $this->travel(25)->hours();
    $this->artisan('peopleos:workflows:tick')->assertSuccessful();

    $instance->refresh();
    expect($instance->status)->toBe(InstanceStatus::Completed);
    Queue::assertPushed(SendWebhook::class, fn (SendWebhook $job) => $job->url === 'https://it.example.test/hooks/'.$this->employee->employee_code);

    $deliveries = NotificationDelivery::query()->where('event', 'workflow.notification')->get();
    expect($deliveries->pluck('user_id')->all())->toEqualCanonicalizing([$this->employee->user_id, $this->manager->user_id])
        ->and($deliveries->first()->subject)->toBe('Welcome '.$this->employee->person->first_name)
        ->and($deliveries->first()->body)->toBe("Run {$instance->id}");
});

it('executes the webhook job against the configured endpoint', function () {
    Http::fake(['it.example.test/*' => Http::response(['ok' => true], 200)]);
    [$nodes, $edges] = linear([['id' => 'hook', 'type' => 'webhook', 'name' => 'Hook', 'config' => ['url' => 'https://it.example.test/hooks', 'method' => 'POST', 'headers' => ['X-Key' => 'abc']]]]);
    $instance = $this->engine->start(publishWorkflow($nodes, $edges), $this->employee);

    Http::assertSent(fn ($request) => $request->url() === 'https://it.example.test/hooks' && $request->hasHeader('X-Key', 'abc') && $request['employee']['code'] === $this->employee->employee_code);
    expect($instance->actions()->pluck('action')->all())->toContain('webhook.dispatched', 'webhook.sent');
});

it('cancels open runs and refuses double decisions', function () {
    [$nodes, $edges] = linear([['id' => 'approve', 'type' => 'approval', 'name' => 'Approve', 'config' => ['mode' => 'single', 'approver' => ['type' => 'manager']]]]);
    $instance = $this->engine->start(publishWorkflow($nodes, $edges), $this->employee);
    $task = $instance->tasks->first();

    $this->engine->cancel($instance, 'Withdrawn');
    expect($instance->refresh()->status)->toBe(InstanceStatus::Cancelled)->and($task->fresh()->status)->toBe(TaskStatus::Cancelled);
    expect(fn () => $this->engine->completeTask($task->fresh(), 'approved', null, $this->manager->user))->toThrow(WorkflowException::class, 'already been handled');
    expect(fn () => $this->engine->cancel($instance))->toThrow(WorkflowException::class);
});

it('fails safely when no assignee can be found', function () {
    $orphan = Employee::factory()->create();
    [$nodes, $edges] = linear([['id' => 'approve', 'type' => 'approval', 'name' => 'Approve', 'config' => ['mode' => 'single', 'approver' => ['type' => 'manager']]]]);
    $instance = $this->engine->start(publishWorkflow($nodes, $edges), $orphan);

    expect($instance->status)->toBe(InstanceStatus::Failed)->and($instance->error)->toContain('Could not find an assignee');
});

it('validates definitions before publishing and keeps published versions immutable', function () {
    $workflow = Workflow::create(['name' => 'Broken', 'key' => 'broken']);
    app(Workflows::class)->draft($workflow, ['nodes' => [
        ['id' => 'start', 'type' => 'start', 'name' => 'S', 'config' => []],
        ['id' => 'cond', 'type' => 'condition', 'name' => 'C', 'config' => ['conditions' => [['field' => 'x', 'operator' => 'equals', 'value' => 1]]]],
        ['id' => 'appr', 'type' => 'approval', 'name' => 'A', 'config' => []],
    ], 'edges' => [['from' => 'start', 'to' => 'cond', 'label' => null], ['from' => 'cond', 'to' => 'nowhere', 'label' => 'yes']]]);

    expect(fn () => app(Workflows::class)->publish($workflow))->toThrow(WorkflowException::class, 'End node');

    [$nodes, $edges] = linear([]);
    $ok = publishWorkflow($nodes, $edges);
    expect(fn () => $ok->published()->first()->update(['definition' => []]))->toThrow(WorkflowException::class, 'immutable');
});
