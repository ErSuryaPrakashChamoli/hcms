<?php

use App\Domain\Attendance\Models\WorkSchedule;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveAccrual;
use App\Domain\Leave\Services\Leaves;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Filament\Pages\MyWork;
use Livewire\Livewire;

require_once __DIR__.'/../Leave/LeaveTestHelpers.php';
require_once __DIR__.'/../Attendance/AttendanceTestHelpers.php';
require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/*
| UX.15.7: My work answers "what should I do right now?": one thing to do next (with its person and
| reason), then streams by kind; filters narrow the same workspace; "Later" only snoozes presentation.
*/

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    leavePolicy(['EL' => ['days' => 24, 'accrual_frequency' => 'annual']]);
    $this->manager = employeeWithUser(null, ['leave.view', 'leave.approve', 'task.view', 'employee.view']);
    assignSchedule($this->manager, weeklySchedule(generalShift()));
    $this->employee = employeeWithUser($this->manager, ['leave.apply', 'task.view']);
    forceLifecycle($this->employee, LifecycleState::Active, ['joining_date' => '2025-01-01']);
    assignSchedule($this->employee, WorkSchedule::query()->first());
    app(LeaveAccrual::class)->accrue($this->employee);
    $this->request = app(Leaves::class)->request($this->employee, LeaveType::query()->where('code', 'EL')->first(), '2026-09-22', '2026-09-22', 'School event');
    actAsTenant(null);
});

it('puts the next decision first, with its person, and lists decisions as a stream', function () {
    $this->actingAs($this->manager->user)->get(MyWork::getUrl())->assertOk()
        ->assertSee('Do this next')
        ->assertSee('Start with '.$this->employee->refresh()->display_name.'’s', false)
        ->assertSee('data-person="'.$this->employee->id.'"', false)
        ->assertSee('Decisions')
        ->assertSee('data-approval-id="leave:'.$this->request->id.'"', false);

    // Legacy deep links still work.
    $this->get(MyWork::getUrl(['tab' => 'today']))->assertOk();
});

it('narrows the same workspace with filters and snoozes the next item only in presentation', function () {
    actAsTenant($this->tenant);
    $this->actingAs($this->manager->user);

    $page = Livewire::test(MyWork::class)->call('setFilter', 'waiting');
    expect($page->instance()->filter())->toBe('waiting');
    $page->assertDontSee('Do this next')->assertSee('Nothing in waiting on others right now.');

    expect($page->instance()->work['next']['key'] ?? null)->toBe('leave:'.$this->request->id);
    $page->call('setFilter', 'all')->call('later', 'leave:'.$this->request->id);
    expect($page->instance()->work['next']['key'] ?? null)->not->toBe('leave:'.$this->request->id)
        ->and(LeaveRequest::query()->find($this->request->id)->status)->toBe('pending');
});

it('tells someone with nothing to do that they are caught up', function () {
    $idle = tenantUser($this->tenant, ['task.view']);

    $this->actingAs($idle)->get(MyWork::getUrl())->assertOk()
        ->assertSee('You’re all caught up.', false)
        ->assertSee('No decisions require your attention right now.');
});
