<?php

use App\Domain\Attendance\Models\WorkSchedule;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveAccrual;
use App\Domain\Leave\Services\Leaves;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Filament\Pages\Approvals;
use Livewire\Livewire;

require_once __DIR__.'/../Leave/LeaveTestHelpers.php';
require_once __DIR__.'/../Attendance/AttendanceTestHelpers.php';
require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/*
| UX.15.8: the Approval Center as a decision workspace. It says how many decisions need the person,
| keeps a keyboard queue beside the selected decision, shows the context in the decision, and is
| honest that only some domains can send a request back.
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
    $this->request = app(Leaves::class)->request($this->employee, LeaveType::query()->where('code', 'EL')->first(), '2026-09-30', '2026-10-01', 'Cousin’s wedding');
    actAsTenant(null);
});

it('heads the workspace with what needs the person and pairs the queue with the decision', function () {
    $html = $this->actingAs($this->manager->user)->get(Approvals::getUrl())->assertOk()
        ->assertSee('1 decision needs you')
        ->assertSee('class="pos-stream-row pos-queue-row" data-approval-id="leave:'.$this->request->id.'"', false)
        ->assertSee('Cousin’s wedding', false)
        ->assertSee('Before → After')
        ->assertSee('data-person="'.$this->employee->id.'"', false)
        ->getContent();

    // Leave cannot be sent back in the leave domain: the workspace says so instead of faking it.
    expect($html)->toContain('This kind of request can’t be sent back in PeopleOS.')->not->toContain('data-decision="request_change"');
});

it('moves to the caught-up state once the last decision is made', function () {
    actAsTenant($this->tenant);
    $this->actingAs($this->manager->user);

    Livewire::test(Approvals::class)->call('decide', 'leave:'.$this->request->id, 'approve', null)
        ->assertNotified('Leave approved')
        ->assertSee('No decisions need you')
        ->assertSee('You’re all caught up.', false);
});
