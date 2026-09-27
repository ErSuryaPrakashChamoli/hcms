<?php

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\HolidayCalendar;
use App\Domain\Attendance\Models\HolidayCalendarRule;
use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveAccrual;
use App\Domain\Leave\Services\LeaveAdjustments;
use App\Domain\Leave\Services\LeaveBalances;
use App\Domain\Leave\Services\Leaves;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Domain\Workflow\Services\WorkflowEngine;

require_once __DIR__.'/LeaveTestHelpers.php';
require_once __DIR__.'/../Attendance/AttendanceTestHelpers.php';
require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00'); // Monday
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    leavePolicy([
        'EL' => ['days' => 24, 'accrual_frequency' => 'annual', 'min_notice_days' => 2, 'negative_balance_limit' => 0, 'max_consecutive_days' => 0],
        'CL' => ['days' => 6, 'accrual_frequency' => 'annual', 'max_consecutive_days' => 2, 'probation_eligible' => false],
        'SL' => ['days' => 8, 'accrual_frequency' => 'annual', 'document_required_after_days' => 2, 'min_notice_days' => 0],
    ]);
    $this->shift = generalShift();
    $this->schedule = weeklySchedule($this->shift);
    $this->manager = employeeWithUser();
    $this->employee = employeeWithUser($this->manager, ['leave.apply', 'task.view', 'task.act']);
    forceLifecycle($this->employee, LifecycleState::Active, ['joining_date' => '2025-01-01']);
    assignSchedule($this->employee, $this->schedule);
    app(LeaveAccrual::class)->accrue($this->employee);
    $this->leaves = app(Leaves::class);
    $this->balances = app(LeaveBalances::class);
    $this->el = LeaveType::query()->where('code', 'EL')->first();
    $this->cl = LeaveType::query()->where('code', 'CL')->first();
    $this->sl = LeaveType::query()->where('code', 'SL')->first();
    $this->lwp = LeaveType::query()->where('code', 'LWP')->first();
});

it('counts working days, skipping weekly offs and holidays, and supports half days', function () {
    $calendar = HolidayCalendar::create(['name' => 'Nat', 'code' => 'NAT']);
    Holiday::create(['holiday_calendar_id' => $calendar->id, 'date' => '2026-10-02', 'name' => 'Gandhi Jayanti']);
    HolidayCalendarRule::create(['holiday_calendar_id' => $calendar->id, 'name' => 'All', 'conditions' => []]);

    $request = $this->leaves->request($this->employee, $this->el, '2026-10-01', '2026-10-06', 'Trip', 'second_half', 'first_half');

    expect((float) $request->days)->toBe(2.0)
        ->and(array_column($request->dates, 'date'))->toBe(['2026-10-01', '2026-10-05', '2026-10-06'])
        ->and($request->sessionOn('2026-10-01'))->toBe('second_half')
        ->and($request->sessionOn('2026-10-06'))->toBe('first_half')
        ->and($request->status)->toBe('pending')
        ->and((float) $this->balances->balance($this->employee, $this->el, 2026)->pending)->toBe(2.0)
        ->and($this->balances->balance($this->employee, $this->el, 2026)->available())->toBe(22.0);
});

it('enforces policy rules: notice, consecutive days, probation, documents, balance, overlap', function () {
    expect(fn () => $this->leaves->request($this->employee, $this->el, '2026-09-22', '2026-09-22', 'late'))->toThrow(RuntimeException::class, 'notice');
    expect(fn () => $this->leaves->request($this->employee, $this->cl, '2026-10-05', '2026-10-07', 'long'))->toThrow(RuntimeException::class, 'at most 2');
    expect(fn () => $this->leaves->request($this->employee, $this->sl, '2026-09-22', '2026-09-24', 'sick'))->toThrow(RuntimeException::class, 'document');
    expect(fn () => $this->leaves->request($this->employee, $this->el, '2026-10-05', '2026-11-30', 'too long'))->toThrow(RuntimeException::class, 'Insufficient');
    expect(fn () => $this->leaves->request($this->employee, LeaveType::query()->where('code', 'ML')->first(), '2026-10-05', '2026-10-06', 'x'))->toThrow(RuntimeException::class);

    $probationer = employeeWithUser($this->manager);
    assignSchedule($probationer, $this->schedule);
    expect(fn () => $this->leaves->request($probationer, $this->cl, '2026-10-05', '2026-10-05', 'x'))->toThrow(RuntimeException::class, 'probation');

    $this->leaves->request($this->employee, $this->el, '2026-10-05', '2026-10-06', 'first');
    expect(fn () => $this->leaves->request($this->employee, $this->el, '2026-10-06', '2026-10-07', 'overlap'))->toThrow(RuntimeException::class, 'already exists');
    expect(fn () => $this->leaves->request($this->employee, $this->el, '2026-10-10', '2026-10-11', 'weekend'))->toThrow(RuntimeException::class, 'no working days');
});

it('approves: posts usage, marks attendance as leave, and cancellation reverses everything', function () {
    $request = $this->leaves->request($this->employee, $this->el, '2026-09-23', '2026-09-24', 'Family', requester: $this->employee->user);
    $this->leaves->approve($request, 'Enjoy', $this->manager->user);

    $request->refresh();
    $balance = $this->balances->balance($this->employee, $this->el, 2026);
    expect($request->status)->toBe('approved')
        ->and($request->reviewed_by)->toBe($this->manager->user_id)
        ->and((float) $balance->used)->toBe(2.0)
        ->and((float) $balance->pending)->toBe(0.0)
        ->and($balance->available())->toBe(22.0)
        ->and($this->employee->timelineEntries()->where('category', 'leave')->exists())->toBeTrue()
        ->and(NotificationDelivery::query()->count())->toBe(0);

    $this->travelTo('2026-09-25 10:00:00');
    $record = app(AttendanceProcessor::class)->process($this->employee, '2026-09-23');
    expect($record->status)->toBe('leave')->and($record->leave_request_id)->toBe($request->id)->and($record->exceptions)->toBeNull();

    $this->leaves->cancel($request, 'Plans changed', $this->employee->user);
    expect($request->fresh()->status)->toBe('cancelled')
        ->and($this->balances->balance($this->employee, $this->el, 2026)->available())->toBe(24.0)
        ->and(AttendanceRecord::query()->where('employee_id', $this->employee->id)->whereDate('date', '2026-09-23')->value('status'))->toBe('absent');
});

it('treats half-day leave with punches as present and unpaid leave as unpaid', function () {
    $half = $this->leaves->request($this->employee, $this->el, '2026-09-23', '2026-09-23', 'Half', 'first_half');
    $this->leaves->approve($half);
    punch($this->employee, '2026-09-23 14:00:00');
    punch($this->employee, '2026-09-23 18:30:00');
    $this->travelTo('2026-09-24 10:00:00');
    $record = app(AttendanceProcessor::class)->process($this->employee, '2026-09-23');
    expect($record->status)->toBe('present')->and($record->is_half_day_leave)->toBeTrue()->and($record->exceptions)->toBeNull();

    $lwp = $this->leaves->request($this->employee, $this->lwp, '2026-09-28', '2026-09-28', 'No balance');
    $this->leaves->approve($lwp);
    $this->travelTo('2026-09-29 10:00:00');
    expect(app(AttendanceProcessor::class)->process($this->employee, '2026-09-28')->status)->toBe('unpaid_leave');
});

it('rejects, refuses double decisions and blocks self-approval by policy', function () {
    $request = $this->leaves->request($this->employee, $this->el, '2026-09-23', '2026-09-23', 'x');
    $this->leaves->reject($request, 'Busy week');

    expect($request->fresh()->status)->toBe('rejected')
        ->and($this->balances->balance($this->employee, $this->el, 2026)->available())->toBe(24.0);
    expect(fn () => $this->leaves->approve($request))->toThrow(RuntimeException::class, 'already been decided');
    expect($this->employee->user->can('approve', $request))->toBeFalse()
        ->and($this->manager->user->can('approve', $request))->toBeFalse();
});

it('adjusts balances, credits comp-off and encashes within limits', function () {
    $adjustments = app(LeaveAdjustments::class);
    $adjustments->adjust($this->employee, $this->el, -4, 'Correction');
    expect($this->balances->balance($this->employee, $this->el, 2026)->available())->toBe(20.0);

    $adjustments->creditCompOff($this->employee, 1, 'Worked on Gandhi Jayanti');
    $co = LeaveType::query()->where('code', 'CO')->first();
    expect((float) $this->balances->balance($this->employee, $co, 2026)->accrued)->toBe(1.0);

    expect(fn () => $adjustments->requestEncashment($this->employee, $this->el, 5))->toThrow(RuntimeException::class, 'cannot be encashed');

    leavePolicy(['EL' => ['days' => 24, 'accrual_frequency' => 'annual', 'encashment_allowed' => true, 'max_encash_days' => 10]], [], 'ENCASH');
    PolicyAssignmentRule::query()->where('name', 'Everyone')->where('policy_type', 'leave')->orderBy('id')->first()->delete();

    $encashment = $adjustments->requestEncashment($this->employee, $this->el, 8, 'Year end');
    $adjustments->approveEncashment($encashment);
    expect($encashment->fresh()->status)->toBe('approved')
        ->and($this->balances->balance($this->employee, $this->el, 2026)->available())->toBe(12.0);
    expect(fn () => $adjustments->requestEncashment($this->employee, $this->el, 3))->toThrow(RuntimeException::class, 'At most 10');
});

it('lets a workflow decide a leave request', function () {
    [$nodes, $edges] = linear([['id' => 'approve', 'type' => 'approval', 'name' => 'Manager approval', 'config' => ['mode' => 'single', 'approver' => ['type' => 'manager']]]]);
    publishWorkflow($nodes, $edges, ['trigger_event' => 'leave.requested', 'subject_type' => LeaveRequest::class]);

    $request = $this->leaves->request($this->employee, $this->el, '2026-09-23', '2026-09-23', 'Via workflow');
    $instance = WorkflowInstance::query()->where('subject_id', $request->id)->first();

    expect($instance)->not->toBeNull()->and($instance->tasks->first()->assignee_id)->toBe($this->manager->user_id);

    app(WorkflowEngine::class)->completeTask($instance->tasks->first(), 'approved', 'Go ahead', $this->manager->user);

    expect($request->fresh()->status)->toBe('approved')
        ->and($request->fresh()->review_note)->toContain('Go ahead')
        ->and($this->balances->balance($this->employee, $this->el, 2026)->available())->toBe(23.0);
});
