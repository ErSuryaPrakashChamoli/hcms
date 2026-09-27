<?php

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\HolidayCalendar;
use App\Domain\Attendance\Models\HolidayCalendarRule;
use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Attendance\Services\Regularisations;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Models\Employee;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Notifications\Models\NotificationRule;
use App\Domain\Notifications\Models\NotificationTemplate;
use App\Domain\Workflow\Models\WorkflowInstance;

require_once __DIR__.'/AttendanceTestHelpers.php';
require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->shift = generalShift();
    $this->schedule = weeklySchedule($this->shift);
    $this->employee = Employee::factory()->create(['joining_date' => '2025-01-01']);
    assignSchedule($this->employee, $this->schedule);
    $this->processor = app(AttendanceProcessor::class);
    $this->wednesday = '2026-09-23';
});

it('computes a normal present day', function () {
    punch($this->employee, "{$this->wednesday} 09:05:00", 'in');
    punch($this->employee, "{$this->wednesday} 18:10:00", 'out');

    $record = $this->processor->process($this->employee, $this->wednesday);

    expect($record->status)->toBe('present')
        ->and($record->shift_id)->toBe($this->shift->id)
        ->and($record->worked_minutes)->toBe(485)
        ->and($record->late_minutes)->toBe(0)
        ->and($record->exceptions)->toBeNull()
        ->and($record->overtime_minutes)->toBe(0)
        ->and(AttendanceRecord::query()->count())->toBe(1);
});

it('flags late coming beyond grace, early leaving, short hours and half days', function () {
    punch($this->employee, "{$this->wednesday} 09:40:00");
    punch($this->employee, "{$this->wednesday} 17:30:00");

    $record = $this->processor->process($this->employee, $this->wednesday);

    expect($record->status)->toBe('half_day')
        ->and($record->late_minutes)->toBe(40)
        ->and($record->early_leave_minutes)->toBe(30)
        ->and($record->worked_minutes)->toBe(410)
        ->and($record->exceptions)->toEqualCanonicalizing(['short_hours', 'late', 'early_leave']);
});

it('marks missed punches, absences, weekly offs and holidays', function () {
    punch($this->employee, "{$this->wednesday} 09:00:00", 'in');
    $incomplete = $this->processor->process($this->employee, $this->wednesday);
    expect($incomplete->status)->toBe('incomplete')->and($incomplete->exceptions)->toBe(['missed_punch']);

    $absent = $this->processor->process($this->employee, '2026-09-24');
    expect($absent->status)->toBe('absent')->and($absent->exceptions)->toBe(['absent']);

    $off = $this->processor->process($this->employee, '2026-09-26');
    expect($off->status)->toBe('weekly_off')->and($off->exceptions)->toBeNull();

    $calendar = HolidayCalendar::create(['name' => 'National', 'code' => 'NAT']);
    Holiday::create(['holiday_calendar_id' => $calendar->id, 'date' => '2026-10-02', 'name' => 'Gandhi Jayanti']);
    HolidayCalendarRule::create(['holiday_calendar_id' => $calendar->id, 'name' => 'All', 'conditions' => []]);
    $holiday = $this->processor->process($this->employee, '2026-10-02');
    expect($holiday->status)->toBe('holiday')->and($holiday->holiday_name)->toBe('Gandhi Jayanti');

    // Working on a holiday: present, flagged, and every minute is overtime.
    punch($this->employee, '2026-10-02 10:00:00');
    punch($this->employee, '2026-10-02 15:00:00');
    $worked = $this->processor->process($this->employee, '2026-10-02');
    expect($worked->status)->toBe('present')->and($worked->exceptions)->toContain('holiday_work')->and($worked->overtime_minutes)->toBe(240);
});

it('handles cross-midnight shifts within the punch window', function () {
    $night = generalShift(['name' => 'Night', 'code' => 'NIGHT', 'start_time' => '22:00:00', 'end_time' => '07:00:00', 'crosses_midnight' => true, 'break_minutes' => 30]);
    $schedule = weeklySchedule($night, ['name' => 'Nights', 'code' => 'NIGHTS']);
    $nightOwl = Employee::factory()->create();
    assignSchedule($nightOwl, $schedule);

    punch($nightOwl, '2026-09-23 21:55:00');
    punch($nightOwl, '2026-09-24 07:05:00');

    $record = $this->processor->process($nightOwl, '2026-09-23');

    expect($record->status)->toBe('present')
        ->and($record->worked_minutes)->toBe(520)
        ->and($record->late_minutes)->toBe(0)
        ->and($record->first_in->toDateTimeString())->toBe('2026-09-23 21:55:00')
        ->and($record->last_out->toDateTimeString())->toBe('2026-09-24 07:05:00');
});

it('computes overtime beyond the full day and applies policy settings', function () {
    $policy = Policy::create(['type' => 'overtime', 'name' => 'OT', 'code' => 'OT']);
    app(Policies::class)->draft($policy, ['minimum_minutes' => 30, 'max_hours_per_week' => 10, 'approval_required' => true]);
    app(Policies::class)->publish($policy, '2026-01-01');
    PolicyAssignmentRule::create(['policy_type' => 'overtime', 'policy_id' => $policy->id, 'name' => 'All', 'conditions' => []]);

    $attendance = Policy::create(['type' => 'attendance', 'name' => 'Att', 'code' => 'ATT']);
    app(Policies::class)->draft($attendance, ['grace_minutes' => 5]);
    app(Policies::class)->publish($attendance, '2026-01-01');
    PolicyAssignmentRule::create(['policy_type' => 'attendance', 'policy_id' => $attendance->id, 'name' => 'All', 'conditions' => []]);

    punch($this->employee, "{$this->wednesday} 09:10:00");
    punch($this->employee, "{$this->wednesday} 20:15:00");

    $record = $this->processor->process($this->employee, $this->wednesday);

    expect($record->status)->toBe('present')
        ->and($record->late_minutes)->toBe(10)
        ->and($record->worked_minutes)->toBe(605)
        ->and($record->overtime_minutes)->toBe(125)
        ->and($record->overtime_approved_minutes)->toBe(0)
        ->and($record->exceptions)->toContain('overtime')
        ->and(NotificationDelivery::query()->count())->toBe(0);

    app(Regularisations::class)->approveOvertime($record, 120, 'Approved 2h');
    expect($record->fresh()->overtime_approved_minutes)->toBe(120)->and($record->fresh()->exceptions)->toBe(['late']);

    // Reprocessing keeps the approval but never above the recomputed overtime.
    $this->processor->process($this->employee, $this->wednesday);
    expect($record->fresh()->overtime_approved_minutes)->toBe(120);
});

it('applies approved regularisations and reprocesses', function () {
    punch($this->employee, "{$this->wednesday} 09:00:00", 'in');
    $this->processor->process($this->employee, $this->wednesday);
    $this->travelTo('2026-09-25 10:00:00');

    $regularisations = app(Regularisations::class);
    $request = $regularisations->request($this->employee, $this->wednesday, 'missed_punch', 'Forgot to punch out', null, "{$this->wednesday} 18:00:00");

    expect($request->status)->toBe('pending')
        ->and(fn () => $regularisations->request($this->employee, $this->wednesday, 'missed_punch', 'again'))->toThrow(RuntimeException::class, 'already pending');

    $regularisations->approve($request, 'OK');
    $record = AttendanceRecord::query()->where('employee_id', $this->employee->id)->whereDate('date', $this->wednesday)->first();

    expect($record->status)->toBe('present')
        ->and($record->is_regularised)->toBeTrue()
        ->and($record->last_out->format('H:i'))->toBe('18:00')
        ->and($record->exceptions)->toBeNull();

    $wfh = $regularisations->request($this->employee, '2026-09-24', 'wfh', 'Worked from home');
    $regularisations->approve($wfh);
    expect(AttendanceRecord::query()->where('employee_id', $this->employee->id)->whereDate('date', '2026-09-24')->value('status'))->toBe('wfh');

    expect(fn () => $regularisations->request($this->employee, '2026-08-01', 'late', 'too old'))->toThrow(RuntimeException::class, 'within');
});

it('rejects regularisations and refuses locked days', function () {
    $this->travelTo('2026-09-24 10:00:00');
    $regularisations = app(Regularisations::class);
    $request = $regularisations->request($this->employee, $this->wednesday, 'late', 'Traffic');
    $regularisations->reject($request, 'Not acceptable');
    expect($request->fresh()->status)->toBe('rejected');
    expect(fn () => $regularisations->approve($request))->toThrow(RuntimeException::class, 'already been reviewed');

    AttendanceRecord::query()->create(['employee_id' => $this->employee->id, 'date' => '2026-09-22', 'status' => 'present', 'is_locked' => true]);
    expect(fn () => $regularisations->request($this->employee, '2026-09-22', 'late', 'x'))->toThrow(RuntimeException::class, 'locked');
    expect($this->processor->process($this->employee, '2026-09-22')->status)->toBe('present');
});

it('feeds exceptions and regularisation requests into notifications and workflows', function () {
    $this->travelTo('2026-09-24 10:00:00');
    $template = NotificationTemplate::create(['key' => 'reg', 'name' => 'Reg', 'subject' => 'Regularisation for {{ employee.name }} on {{ attendance.date }}', 'body' => '{{ attendance.reason }}']);
    NotificationRule::create(['name' => 'Tell HR', 'event' => 'attendance.regularisation_requested', 'audience' => [['type' => 'user', 'user_id' => $this->hr->id]], 'channels' => ['in_app'], 'notification_template_id' => $template->id]);
    [$nodes, $edges] = linear([['id' => 'approve', 'type' => 'approval', 'name' => 'Approve regularisation', 'config' => ['mode' => 'single', 'approver' => ['type' => 'user', 'user_id' => $this->hr->id]]]]);
    publishWorkflow($nodes, $edges, ['trigger_event' => 'attendance.regularisation_requested']);

    $request = app(Regularisations::class)->request($this->employee, $this->wednesday, 'late', 'Traffic jam');

    expect(NotificationDelivery::query()->where('event', 'attendance.regularisation_requested')->value('subject'))->toBe("Regularisation for {$this->employee->person->display_name} on {$this->wednesday}")
        ->and(WorkflowInstance::query()->where('subject_id', $request->id)->exists())->toBeTrue();
});

it('processes every employed employee for a date through the command', function () {
    $other = Employee::factory()->create();
    assignSchedule($other, $this->schedule);
    punch($other, "{$this->wednesday} 09:00:00");
    punch($other, "{$this->wednesday} 18:00:00");

    $this->artisan('peopleos:attendance:process', ['--date' => $this->wednesday])->assertSuccessful();

    expect(AttendanceRecord::query()->whereDate('date', $this->wednesday)->count())->toBe(2)
        ->and(AttendanceRecord::query()->where('employee_id', $other->id)->value('status'))->toBe('present')
        ->and(AttendanceRecord::query()->where('employee_id', $this->employee->id)->value('status'))->toBe('absent');
});
