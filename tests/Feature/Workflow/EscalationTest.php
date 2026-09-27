<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Identity\Models\Role;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Workflow\Services\EscalationEngine;
use App\Domain\Workflow\Services\WorkflowEngine;

require_once __DIR__.'/WorkflowTestHelpers.php';

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['*']));
    $this->engine = app(WorkflowEngine::class);
    $this->escalations = app(EscalationEngine::class);
    $this->director = employeeWithUser();
    $this->manager = employeeWithUser($this->director);
    $this->employee = employeeWithUser($this->manager);
});

it('reminds on overdue tasks without configured steps, once a day', function () {
    [$nodes, $edges] = linear([['id' => 'approve', 'type' => 'approval', 'name' => 'Approve', 'config' => ['mode' => 'single', 'approver' => ['type' => 'manager'], 'sla_hours' => 4]]]);
    $instance = $this->engine->start(publishWorkflow($nodes, $edges), $this->employee);

    expect($this->escalations->run())->toBe(0);

    $this->travel(5)->hours();
    expect($this->escalations->run())->toBe(1)
        ->and($this->escalations->run())->toBe(0)
        ->and(NotificationDelivery::query()->where('event', 'workflow.task.reminder')->where('user_id', $this->manager->user_id)->exists())->toBeTrue();

    $this->travel(25)->hours();
    expect($this->escalations->run())->toBe(1);
});

it('follows configured steps: remind, then escalate to the manager, then to a role', function () {
    $hrRole = Role::query()->where('slug', 'hr-manager')->first();
    [$nodes, $edges] = linear([['id' => 'approve', 'type' => 'approval', 'name' => 'Approve', 'config' => [
        'mode' => 'single', 'approver' => ['type' => 'manager'], 'sla_hours' => 24,
        'escalation' => [
            ['after_hours' => 24, 'action' => 'remind'],
            ['after_hours' => 48, 'action' => 'escalate_to_manager'],
            ['after_hours' => 72, 'action' => 'escalate_to_role', 'role_id' => $hrRole->id],
        ],
    ]]]);
    $instance = $this->engine->start(publishWorkflow($nodes, $edges), $this->employee);
    $task = $instance->tasks->first();

    $this->travel(25)->hours();
    $this->escalations->run();
    expect($task->fresh()->escalation_level)->toBe(1)->and($task->fresh()->assignee_id)->toBe($this->manager->user_id);

    $this->travel(24)->hours();
    $this->escalations->run();
    expect($task->fresh()->escalation_level)->toBe(2)
        ->and($task->fresh()->assignee_id)->toBe($this->director->user_id)
        ->and(AuditEvent::query()->where('action', 'ESCALATED')->exists())->toBeTrue();

    $this->travel(24)->hours();
    $this->escalations->run();
    expect($task->fresh()->escalation_level)->toBe(3)
        ->and($task->fresh()->assignee_id)->toBeNull()
        ->and($task->fresh()->assignee_role_id)->toBe($hrRole->id);

    // The director can no longer act; an HR manager can.
    expect($task->fresh()->isActionableBy($this->director->user))->toBeFalse();
});
