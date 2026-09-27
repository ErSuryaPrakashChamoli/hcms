<?php

use App\Domain\Employment\Models\Employee;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Services\LifecycleEngine;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Notifications\Models\NotificationRule;
use App\Domain\Notifications\Models\NotificationTemplate;
use App\Domain\Workflow\Models\WorkflowInstance;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->manager = employeeWithUser();
    forceLifecycle($this->manager, LifecycleState::Active);

    $template = NotificationTemplate::create(['key' => 'reminder', 'name' => 'Reminder', 'subject' => '{{ employee.name }}: {{ reminder.days_left }} days', 'body' => 'x']);
    foreach (['employee.joining_due', 'employee.probation_ending', 'employee.probation_overdue'] as $event) {
        NotificationRule::create(['name' => $event, 'event' => $event, 'audience' => [['type' => 'manager'], ['type' => 'user', 'user_id' => $this->hr->id]], 'channels' => ['in_app'], 'notification_template_id' => $template->id]);
    }
});

it('emits joining, probation-ending and probation-overdue events that rules and workflows react to', function () {
    [$nodes, $edges] = linear([['id' => 'review', 'type' => 'task', 'name' => 'Decide on {{ employee.name }}', 'config' => ['assignee' => ['type' => 'manager']]]]);
    publishWorkflow($nodes, $edges, ['trigger_event' => 'employee.probation_ending']);

    $joiner = employeeWithUser($this->manager);
    forceLifecycle($joiner, LifecycleState::Preboarding, ['expected_joining_date' => now()->addDays(2)]);
    $ending = employeeWithUser($this->manager);
    forceLifecycle($ending, LifecycleState::Probation, ['probation_end_date' => now()->addDays(5)]);
    $overdue = employeeWithUser($this->manager);
    forceLifecycle($overdue, LifecycleState::Probation, ['probation_end_date' => now()->subDays(3)]);
    $fine = employeeWithUser($this->manager);
    forceLifecycle($fine, LifecycleState::Probation, ['probation_end_date' => now()->addMonths(3)]);

    $this->artisan('peopleos:lifecycle:reminders')->assertSuccessful();

    expect(NotificationDelivery::query()->where('event', 'employee.joining_due')->count())->toBe(2)
        ->and(NotificationDelivery::query()->where('event', 'employee.probation_ending')->where('user_id', $this->hr->id)->value('subject'))->toBe($ending->person->display_name.': 5 days')
        ->and(NotificationDelivery::query()->where('event', 'employee.probation_overdue')->count())->toBe(2)
        ->and(WorkflowInstance::query()->where('subject_id', $ending->id)->exists())->toBeTrue()
        ->and(WorkflowInstance::query()->where('subject_id', $fine->id)->exists())->toBeFalse()
        ->and(WorkflowInstance::query()->first()->tasks->first()->assignee_id)->toBe($this->manager->user_id);
});

it('marks a preboarding employee as joined into probation', function () {
    $employee = Employee::factory()->create(['lifecycle_state' => LifecycleState::Preboarding, 'expected_joining_date' => '2026-10-01', 'joining_date' => null]);
    $engine = app(LifecycleEngine::class);

    $engine->transition($employee, LifecycleState::Joined, '2026-10-01');
    $engine->transition($employee, LifecycleState::Probation, '2026-10-01');

    expect($employee->fresh()->joining_date->toDateString())->toBe('2026-10-01')
        ->and($employee->fresh()->lifecycle_state)->toBe(LifecycleState::Probation);
});
