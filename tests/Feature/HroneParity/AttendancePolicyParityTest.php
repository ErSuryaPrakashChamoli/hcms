<?php

use App\Domain\Attendance\Events\AttendanceEvent;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\TimeOfficeRequest;
use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Attendance\Services\Regularisations;
use App\Domain\Attendance\Support\AttendanceDay;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Models\Employee;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveBalances;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/../Attendance/AttendanceTestHelpers.php';
require_once __DIR__.'/../Leave/LeaveTestHelpers.php';

/* HROne setup parity TO-4: attendance policy calculation, entitlement, comp-off generation, absenteeism alerts and the request lock. */

beforeEach(function () {
    $this->travelTo('2026-09-28 10:00:00'); // Monday; the week before runs Mon 21 – Sun 27
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->shift = generalShift();
    $this->employee = Employee::factory()->create(['joining_date' => '2025-01-01']);
    assignSchedule($this->employee, weeklySchedule($this->shift));
    $this->day = fn (string $date) => app(AttendanceProcessor::class)->process($this->employee, $date);
});

function parityPolicy(string $type, array $settings): void
{
    $policy = Policy::create(['type' => $type, 'name' => $type, 'code' => strtoupper($type).'_P']);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => $type, 'policy_id' => $policy->id, 'name' => 'All', 'conditions' => []]);
}

it('counts a single punch as the whole shift, and marks working days present without punches in auto mode', function () {
    parityPolicy('attendance', ['punch_mode' => 'single']);
    punch($this->employee, '2026-09-24 09:10:00', 'in');
    $single = ($this->day)('2026-09-24');
    expect($single->status)->toBe('present')->and($single->worked_minutes)->toBe(480)->and($single->calculation_basis['punch_mode'])->toBe('single');

    Policy::query()->delete();
    parityPolicy('attendance', ['punch_mode' => 'auto']);
    expect(($this->day)('2026-09-23')->status)->toBe('present')
        ->and(($this->day)('2026-09-26')->status)->toBe('weekly_off');
});

it('drops late marks under flexi timings, counts hours only within the shift, and leaves an unpaid weekly off unpaid', function () {
    parityPolicy('attendance', ['timing' => 'flexi', 'count_within_shift' => true, 'weekly_off_paid' => 'unpaid']);
    punch($this->employee, '2026-09-24 10:30:00', 'in');
    punch($this->employee, '2026-09-24 19:30:00', 'out');
    punch($this->employee, '2026-09-23 07:00:00', 'in');
    punch($this->employee, '2026-09-23 18:00:00', 'out');

    $late = ($this->day)('2026-09-24');
    $early = ($this->day)('2026-09-23');
    $off = ($this->day)('2026-09-26');
    expect($late->late_minutes)->toBe(0)->and($late->exceptions ?? [])->not->toContain('late')
        ->and($early->worked_minutes)->toBe(480) // 09:00–18:00 less the hour's break, not 07:00–18:00
        ->and($off->status)->toBe('weekly_off')->and($off->exceptions)->toContain('unpaid_off')
        ->and(AttendanceDay::fromRecord($off)->isPaidDay)->toBeFalse()
        ->and(AttendanceDay::paidQuantity('weekly_off', false, ['unpaid_off']))->toBe(0.0)
        ->and(AttendanceDay::paidQuantity('weekly_off', false))->toBe(1.0);
});

it('earns the weekly off only with enough days present in the week, and re-decides it when a weekday changes', function () {
    parityPolicy('attendance', ['weekly_off_entitlement' => true, 'weekly_off_min_days' => 3]);
    foreach (['2026-09-21', '2026-09-22'] as $present) {
        punch($this->employee, "{$present} 09:00:00", 'in');
        punch($this->employee, "{$present} 18:00:00", 'out');
    }
    foreach (['2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25'] as $date) {
        ($this->day)($date);
    }
    $saturday = ($this->day)('2026-09-26');
    expect($saturday->status)->toBe('absent')->and($saturday->exceptions)->toBe(['weekly_off_not_earned']);

    punch($this->employee, '2026-09-23 09:00:00', 'in');
    punch($this->employee, '2026-09-23 18:00:00', 'out');
    ($this->day)('2026-09-23');
    expect(AttendanceRecord::query()->whereDate('date', '2026-09-26')->value('status'))->toBe('weekly_off');
});

it('generates and approves comp-off for work on a weekly off when the time-office policy says so', function () {
    leavePolicy(['CO' => ['days' => 0]]);
    LeaveType::query()->where('code', 'CO')->update(['category' => 'comp_off']);
    parityPolicy('time_office', ['comp_off_auto' => true, 'comp_off_auto_approve' => true, 'comp_off_full_day_minutes' => 420, 'claim_window_days' => 30]);
    punch($this->employee, '2026-09-27 09:00:00', 'in'); // Sunday
    punch($this->employee, '2026-09-27 17:30:00', 'out');
    ($this->day)('2026-09-27');

    $claim = TimeOfficeRequest::query()->where('type', 'comp_off')->firstOrFail();
    expect($claim->status)->toBe('approved')->and((float) $claim->days)->toBe(1.0)
        ->and(app(LeaveBalances::class)->balance($this->employee, LeaveType::query()->where('code', 'CO')->first(), 2026)->available())->toBe(1.0);

    ($this->day)('2026-09-27'); // processing again does not generate a second claim
    expect(TimeOfficeRequest::query()->where('type', 'comp_off')->count())->toBe(1);
});

it('raises one absenteeism alert for a run of absences, and locks last month\'s requests during processing days', function () {
    parityPolicy('attendance', ['absence_alert_days' => 3, 'request_lock' => true, 'request_lock_from_day' => 1, 'request_lock_to_day' => 5]);
    foreach (['2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25'] as $date) {
        ($this->day)($date);
    }
    punch($this->employee, '2026-09-21 09:00:00', 'in');
    punch($this->employee, '2026-09-21 18:00:00', 'out');
    ($this->day)('2026-09-21');

    Event::fake([AttendanceEvent::class]);
    $this->artisan('peopleos:attendance:absence-alerts', ['--date' => '2026-09-24'])->assertSuccessful();
    Event::assertDispatched(AttendanceEvent::class, fn (AttendanceEvent $e) => $e->name === 'attendance.absence_alert' && $e->context['from'] === '2026-09-22' && $e->context['days'] === 3);
    Event::fake([AttendanceEvent::class]);
    $this->artisan('peopleos:attendance:absence-alerts', ['--date' => '2026-09-25'])->assertSuccessful(); // the same run, a day later: no second alert
    Event::assertNotDispatched(AttendanceEvent::class, fn (AttendanceEvent $e) => $e->name === 'attendance.absence_alert');

    $this->travelTo('2026-10-03 10:00:00');
    expect(fn () => app(Regularisations::class)->request($this->employee, '2026-09-30', 'missed_punch', 'Forgot'))->toThrow(RuntimeException::class, 'locked from day 1 to day 5')
        ->and(app(Regularisations::class)->request($this->employee, '2026-10-01', 'missed_punch', 'Forgot')->status)->toBe('pending');
});
