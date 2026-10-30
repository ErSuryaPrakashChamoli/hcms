<?php

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Attendance\Services\TimeOfficeRequests;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Models\Employee;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveAccrual;
use App\Domain\Leave\Services\LeaveAdjustments;
use App\Domain\Leave\Services\LeaveBalances;
use App\Domain\Leave\Services\Leaves;
use App\Domain\Platform\Services\SettingsRepository;

require_once __DIR__.'/../Attendance/AttendanceTestHelpers.php';
require_once __DIR__.'/../Leave/LeaveTestHelpers.php';

/* HROne parity HP-1b: time-office and leave options configured per client (CF-02, CF-03, CF-04, CF-08, CF-09). */

beforeEach(function () {
    $this->travelTo('2026-09-25 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->employee = Employee::factory()->create(['joining_date' => '2025-01-01']);
    assignSchedule($this->employee, weeklySchedule(generalShift()));
    $this->processor = app(AttendanceProcessor::class);
    $this->requests = app(TimeOfficeRequests::class);
});

function configPolicy(string $type, array $settings, string $code): void
{
    $policy = Policy::create(['type' => $type, 'name' => $code, 'code' => $code]);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => $type, 'policy_id' => $policy->id, 'name' => 'All', 'conditions' => []]);
}

it('credits half-yearly and applies the rule of 15 to the joining half', function () {
    leavePolicy(['EL' => ['days' => 12, 'accrual_frequency' => 'half_yearly', 'joiner_credit' => 'rule_of_15_half']]);
    $el = LeaveType::query()->where('code', 'EL')->first();
    $late = Employee::factory()->create(['joining_date' => '2026-02-20']);
    $early = Employee::factory()->create(['joining_date' => '2026-02-10']);

    app(LeaveAccrual::class)->accrue($late, '2026-09-25');
    app(LeaveAccrual::class)->accrue($early, '2026-09-25');
    $balance = fn (Employee $e) => app(LeaveBalances::class)->balance($e, $el, 2026)->closing;

    expect((float) $balance($late))->toBe(9.0)   // half of the first half (3) + the second half (6)
        ->and((float) $balance($early))->toBe(12.0);
});

it('makes a weekly off between two absent days absent under the sandwich rule, also after the fact', function () {
    app(SettingsRepository::class)->set('attendance.sandwich_rule', 'weekly_off', 'test');
    foreach (['2026-09-18', '2026-09-19', '2026-09-20'] as $day) { // Fri absent, Sat/Sun off
        $this->processor->process($this->employee, $day);
    }
    expect(AttendanceRecord::query()->whereDate('date', '2026-09-19')->value('status'))->toBe('weekly_off');

    $this->processor->process($this->employee, '2026-09-21'); // Monday absent: the weekend is sandwiched
    expect(AttendanceRecord::query()->whereDate('date', '2026-09-19')->value('status'))->toBe('absent')
        ->and(AttendanceRecord::query()->whereDate('date', '2026-09-20')->first()->exceptions)->toBe(['sandwich']);

    punch($this->employee, '2026-09-21 09:00:00', 'in');
    punch($this->employee, '2026-09-21 18:00:00', 'out');
    $this->processor->process($this->employee, '2026-09-21'); // Monday present: the weekend is off again
    expect(AttendanceRecord::query()->whereDate('date', '2026-09-20')->value('status'))->toBe('weekly_off');
});

it('refuses leave for a day attendance shows as present, when configured', function () {
    leavePolicy(['CL' => ['days' => 12]]);
    $cl = LeaveType::query()->where('code', 'CL')->first();
    app(LeaveAdjustments::class)->opening($this->employee, $cl, 12, 2026, 'Go-live');
    punch($this->employee, '2026-09-23 09:00:00', 'in');
    punch($this->employee, '2026-09-23 18:00:00', 'out');
    $this->processor->process($this->employee, '2026-09-23');

    app(SettingsRepository::class)->set('leave.block_on_present_day', true, 'test');
    expect(fn () => app(Leaves::class)->request($this->employee, $cl, '2026-09-23', '2026-09-23', 'Oops'))->toThrow(RuntimeException::class, 'present');
});

it('takes leave for short leave beyond the monthly limit when the policy says so, and gives it back on cancellation', function () {
    leavePolicy(['CL' => ['days' => 12]]);
    $cl = LeaveType::query()->where('code', 'CL')->first();
    app(LeaveAdjustments::class)->opening($this->employee, $cl, 2, 2026, 'Go-live');
    configPolicy('short_leave', ['max_per_month' => 1, 'max_minutes' => 120, 'over_limit' => 'deduct_leave', 'deduct_leave_code' => 'CL', 'deduct_days' => 0.5], 'SL');

    $this->requests->approve($this->requests->request($this->employee, 'short_leave', ['from_date' => '2026-09-21', 'start_time' => '09:00', 'end_time' => '10:00'], 'One'), null, $this->hr);
    expect(fn () => $this->requests->request($this->employee, 'short_leave', ['from_date' => '2026-09-21', 'start_time' => '17:00', 'end_time' => '18:00'], 'Same day'))->toThrow(RuntimeException::class, 'already a short leave');

    $second = $this->requests->request($this->employee, 'short_leave', ['from_date' => '2026-09-22', 'start_time' => '09:00', 'end_time' => '10:00'], 'Two');
    expect($second->effects['deduct'])->toBe(['leave_code' => 'CL', 'days' => 0.5]);
    $this->requests->approve($second, null, $this->hr);
    $balance = fn () => app(LeaveBalances::class)->balance($this->employee, $cl, 2026)->available();
    expect($balance())->toBe(1.5);

    $this->requests->cancel($second->refresh(), 'Not needed', $this->hr);
    expect($balance())->toBe(2.0);
});

it('limits time-office requests per month and makes a very late day a half day', function () {
    configPolicy('time_office', ['on_duty_max_per_month' => 1], 'TO');
    configPolicy('attendance', ['grace_minutes' => 0, 'half_day_if_late_beyond' => 120], 'ATT');

    $this->requests->request($this->employee, 'on_duty', ['from_date' => '2026-09-22', 'session' => 'full'], 'Site');
    expect(fn () => $this->requests->request($this->employee, 'on_duty', ['from_date' => '2026-09-23', 'session' => 'full'], 'Again'))->toThrow(RuntimeException::class, 'at most 1 on duty');

    punch($this->employee, '2026-09-24 11:30:00', 'in');
    punch($this->employee, '2026-09-24 20:30:00', 'out');
    expect($this->processor->process($this->employee, '2026-09-24')->status)->toBe('half_day');
});
