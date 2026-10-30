<?php

use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Services\AttendanceOutput;
use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Attendance\Services\TimeOfficeRequests;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Models\Employee;
use App\Domain\Payroll\Inputs\ShiftAllowanceInput;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Services\PayrollCalculator;

require_once __DIR__.'/../Attendance/AttendanceTestHelpers.php';
require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/* HROne setup parity TO-5: the shift master (absence criteria, counting rules, requests per shift), automatic shift correction, shift allowance. */

beforeEach(function () {
    $this->travelTo('2026-09-28 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->employee = Employee::factory()->create(['joining_date' => '2025-01-01']);
    $this->day = fn (string $date) => app(AttendanceProcessor::class)->process($this->employee, $date);
});

function shiftPolicy(string $type, array $settings): void
{
    $policy = Policy::create(['type' => $type, 'name' => $type, 'code' => strtoupper($type).'_S']);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => $type, 'policy_id' => $policy->id, 'name' => 'All', 'conditions' => []]);
}

it('applies the shift\'s absence criteria and counting rules', function () {
    $shift = generalShift(['absent_first_half_after' => 60, 'absent_full_day_after' => 240, 'absent_second_half_before' => 120, 'ignore_before_start' => true, 'cutoff_time' => '19:00:00', 'full_day_minutes' => 300]);
    assignSchedule($this->employee, weeklySchedule($shift));

    foreach ([['2026-09-21', '10:30', '18:00'], ['2026-09-22', '13:30', '18:00'], ['2026-09-23', '09:00', '15:30'], ['2026-09-24', '07:00', '21:00']] as [$date, $in, $out]) {
        punch($this->employee, "{$date} {$in}:00", 'in');
        punch($this->employee, "{$date} {$out}:00", 'out');
    }
    $late = ($this->day)('2026-09-21');
    $veryLate = ($this->day)('2026-09-22');
    $early = ($this->day)('2026-09-23');
    $long = ($this->day)('2026-09-24');

    expect($late->status)->toBe('half_day')->and($late->exceptions)->toContain('first_half_absent')
        ->and($veryLate->status)->toBe('absent')->and($veryLate->exceptions)->toContain('late_absent')
        ->and($early->status)->toBe('half_day')->and($early->exceptions)->toContain('second_half_absent')
        ->and($long->worked_minutes)->toBe(540); // 09:00 (time before the start not counted) to 19:00 (cut-off) less the hour's break
});

it('picks the scheduled shift nearest the first punch under automatic shift correction', function () {
    $general = generalShift();
    $night = generalShift(['name' => 'Night', 'code' => 'NGT', 'start_time' => '21:00:00', 'end_time' => '06:00:00']);
    $evening = generalShift(['name' => 'Evening', 'code' => 'EVE', 'start_time' => '14:00:00', 'end_time' => '23:00:00']);
    $schedule = weeklySchedule($general, ['auto_shift_ids' => [$general->id, $evening->id, $night->id]]);
    assignSchedule($this->employee, $schedule);

    punch($this->employee, '2026-09-23 13:55:00', 'in');
    punch($this->employee, '2026-09-23 23:05:00', 'out');
    $record = ($this->day)('2026-09-23');
    expect($record->shift_id)->toBe($evening->id)->and($record->calculation_basis['auto_shift'])->toBe('EVE')->and($record->late_minutes)->toBe(0)
        ->and(($this->day)('2026-09-24')->shift_id)->toBe($general->id); // no punches: the scheduled shift
});

it('refuses requests a shift does not allow and shifts employees cannot request', function () {
    $closed = generalShift(['allow_short_leave' => false, 'allow_on_duty' => false]);
    $locked = generalShift(['name' => 'Control room', 'code' => 'CTRL', 'requestable' => false]);
    assignSchedule($this->employee, weeklySchedule($closed));
    $requests = app(TimeOfficeRequests::class);

    expect(fn () => $requests->request($this->employee, 'short_leave', ['from_date' => '2026-09-25', 'start_time' => '10:00', 'end_time' => '11:00'], 'Bank', $this->hr))->toThrow(RuntimeException::class, 'not allowed on the General shift')
        ->and(fn () => $requests->request($this->employee, 'on_duty', ['from_date' => '2026-09-25'], 'Visit', $this->hr))->toThrow(RuntimeException::class, 'not allowed on the General shift')
        ->and(fn () => $requests->request($this->employee, 'shift_change', ['from_date' => '2026-10-05', 'shift_id' => $locked->id], 'Cover', $this->hr))->toThrow(RuntimeException::class, 'cannot be requested');
});

it('pays shift allowance for days on eligible shifts through payroll, with minimum and maximum days', function () {
    syncComplianceRules();
    $company = payrollCompany();
    $night = generalShift(['name' => 'Night', 'code' => 'NGT', 'allowance_eligible' => true]);
    $employee = salariedEmployee(600000, from: '2026-01-01');
    assignSchedule($employee, weeklySchedule($night));
    shiftPolicy('attendance', ['shift_allowance_rate' => 200, 'shift_allowance_min_days' => 2, 'shift_allowance_max_days' => 3]);

    foreach (['2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24'] as $date) {
        punch($employee, "{$date} 09:00:00", 'in');
        punch($employee, "{$date} 18:00:00", 'out');
        app(AttendanceProcessor::class)->process($employee, $date);
    }
    expect(app(AttendanceOutput::class)->shiftAllowanceDays($employee, '2026-09-01', '2026-09-30'))->toBe(4.0);

    $c = app(PayrollCalculator::class)->calculate($employee->refresh(), PayrollPeriod::for($company, 2026, 9));
    $line = collect($c->lines)->firstWhere('code', 'SHIFT_ALW');
    expect($line['amount'])->toBe(600.0)->and($line['basis']['paid_days'])->toBe(3.0)->and($line['taxable'])->toBeTrue()
        ->and((new ShiftAllowanceInput)->key())->toBe('shift_allowance');
});
