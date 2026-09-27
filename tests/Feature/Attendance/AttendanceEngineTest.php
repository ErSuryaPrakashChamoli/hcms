<?php

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Models\ShiftBreak;
use App\Domain\Attendance\Models\WorkScheduleAssignment;
use App\Domain\Attendance\Services\AttendanceOutput;
use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;

require_once __DIR__.'/AttendanceTestHelpers.php';

/* Phase 2 engine: timezones, overnight shifts, breaks, first-in/last-out rules, effective dating, calculation basis, payroll-ready output. */

beforeEach(function () {
    $this->travelTo('2026-09-24 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['*']));
    $this->company = Company::factory()->create();
    $this->processor = app(AttendanceProcessor::class);
    $this->wednesday = '2026-09-23';
});

function employeeAt(?string $timezone): Employee
{
    $location = Location::factory()->create(['company_id' => test()->company->id, 'timezone' => $timezone]);

    return app(HireEmployeeAction::class)->handle(['first_name' => 'Tz', 'last_name' => $timezone ?? 'None'], ['joining_date' => '2026-01-01'], ['company_id' => test()->company->id, 'location_id' => $location->id]);
}

it('calculates in the work location timezone while punches are stored in the application timezone', function () {
    $employee = employeeAt('Asia/Kolkata');
    assignSchedule($employee, weeklySchedule(generalShift())); // 09:00–18:00 local = 03:30–12:30 UTC

    punch($employee, "{$this->wednesday} 03:35:00"); // 09:05 IST, inside grace
    punch($employee, "{$this->wednesday} 12:40:00"); // 18:10 IST

    $record = $this->processor->process($employee, $this->wednesday);
    expect($record->status)->toBe('present')
        ->and($record->timezone)->toBe('Asia/Kolkata')
        ->and($record->scheduled_start->toIso8601String())->toBe('2026-09-23T03:30:00+00:00')
        ->and($record->scheduled_end->toIso8601String())->toBe('2026-09-23T12:30:00+00:00')
        ->and($record->late_minutes)->toBe(0)
        ->and($record->worked_minutes)->toBe(485)
        ->and($record->scheduled_minutes)->toBe(480);

    // The same clock times punched in UTC (i.e. 14:35 IST) are late.
    $other = employeeAt('Asia/Kolkata');
    assignSchedule($other, WorkScheduleAssignment::query()->first()->schedule);
    punch($other, "{$this->wednesday} 09:05:00");
    punch($other, "{$this->wednesday} 18:10:00");
    expect($this->processor->process($other, $this->wednesday)->late_minutes)->toBe(335);
});

it('attributes overnight punches to the shift work date, including late IN after midnight', function () {
    $employee = employeeAt(null);
    $night = generalShift(['name' => 'Night', 'code' => 'NIGHT', 'start_time' => '22:00:00', 'end_time' => '06:00:00', 'crosses_midnight' => true, 'break_minutes' => 30, 'full_day_minutes' => 450, 'half_day_minutes' => 225, 'grace_in_minutes' => 10]);
    assignSchedule($employee, weeklySchedule($night, ['code' => 'NT', 'pattern' => [['mon' => $night->id, 'tue' => $night->id, 'wed' => $night->id, 'thu' => $night->id, 'fri' => $night->id, 'sat' => null, 'sun' => null]]]));

    punch($employee, '2026-09-22 21:58:00', 'in');
    punch($employee, '2026-09-23 06:04:00', 'out');
    punch($employee, '2026-09-23 22:20:00', 'in');   // next night, 20 min late
    punch($employee, '2026-09-24 05:30:00', 'out');  // early by 30

    $tue = $this->processor->process($employee, '2026-09-22');
    $wed = $this->processor->process($employee, '2026-09-23');

    expect($tue->status)->toBe('present')->and($tue->first_in->toDateTimeString())->toBe('2026-09-22 21:58:00')->and($tue->last_out->toDateTimeString())->toBe('2026-09-23 06:04:00')->and($tue->worked_minutes)->toBe(456)->and($tue->late_minutes)->toBe(0)
        ->and($wed->first_in->toDateTimeString())->toBe('2026-09-23 22:20:00')->and($wed->late_minutes)->toBe(20)->and($wed->early_leave_minutes)->toBe(30)->and($wed->status)->toBe('half_day');
    // Yesterday's IN after midnight? An IN at 00:30 belongs to the shift that started the evening before.
    punch($employee, '2026-09-25 00:30:00', 'in');
    punch($employee, '2026-09-25 06:00:00', 'out');
    $thu = $this->processor->process($employee, '2026-09-24');
    expect($thu->first_in->toDateTimeString())->toBe('2026-09-25 00:30:00')->and($thu->late_minutes)->toBe(150)->and(AttendanceRecord::query()->where('employee_id', $employee->id)->whereDate('date', '2026-09-25')->exists())->toBeFalse();
});

it('deducts unpaid breaks only, and falls back to the single break value without configured breaks', function () {
    $employee = employeeAt(null);
    $shift = generalShift(['break_minutes' => 60]);
    ShiftBreak::create(['shift_id' => $shift->id, 'name' => 'Lunch', 'duration_minutes' => 45, 'is_paid' => false]);
    ShiftBreak::create(['shift_id' => $shift->id, 'name' => 'Tea', 'duration_minutes' => 15, 'is_paid' => true]);
    ShiftBreak::create(['shift_id' => $shift->id, 'name' => 'Prayer', 'duration_minutes' => 10, 'is_paid' => false]);
    assignSchedule($employee, weeklySchedule($shift));
    punch($employee, "{$this->wednesday} 09:00:00");
    punch($employee, "{$this->wednesday} 18:00:00");

    $record = $this->processor->process($employee, $this->wednesday);
    expect($shift->fresh()->unpaidBreakMinutes())->toBe(55)->and($record->break_minutes)->toBe(55)->and($record->worked_minutes)->toBe(485)->and($record->scheduled_minutes)->toBe(485);

    ShiftBreak::query()->where('shift_id', $shift->id)->delete();
    expect($shift->fresh()->unpaidBreakMinutes())->toBe(60);
});

it('resolves first IN and last OUT from noisy punches and raises specific exceptions for the rest', function () {
    $employee = employeeAt(null);
    assignSchedule($employee, weeklySchedule(generalShift()));
    $wed = $this->wednesday;

    // Multiple and out-of-order punches: directed ones win, earliest IN / latest OUT.
    punch($employee, "{$wed} 13:00:00", 'in');
    punch($employee, "{$wed} 09:02:00", 'in');
    punch($employee, "{$wed} 17:55:00", 'out');
    punch($employee, "{$wed} 18:20:00", 'out');
    punch($employee, "{$wed} 12:30:00", 'out');
    $record = $this->processor->process($employee, $wed);
    expect($record->first_in->format('H:i'))->toBe('09:02')->and($record->last_out->format('H:i'))->toBe('18:20')->and($record->status)->toBe('present')->and($record->exceptions)->toBeNull();

    // Only OUT punches: missing IN.
    $only = employeeAt(null);
    assignSchedule($only, WorkScheduleAssignment::query()->first()->schedule);
    punch($only, "{$wed} 18:00:00", 'out');
    $r = $this->processor->process($only, $wed);
    expect($r->status)->toBe('incomplete')->and($r->exceptions)->toEqualCanonicalizing(['missing_in', 'missed_punch']);

    // OUT before IN: invalid sequence.
    $rev = employeeAt(null);
    assignSchedule($rev, WorkScheduleAssignment::query()->first()->schedule);
    punch($rev, "{$wed} 18:00:00", 'in');
    punch($rev, "{$wed} 09:00:00", 'out');
    $r = $this->processor->process($rev, $wed);
    expect($r->exceptions)->toContain('invalid_sequence')->and($r->status)->toBe('incomplete');
});

it('handles grace boundaries exactly', function () {
    $employee = employeeAt(null);
    assignSchedule($employee, weeklySchedule(generalShift(['grace_in_minutes' => 10, 'grace_out_minutes' => 5])));
    punch($employee, "{$this->wednesday} 09:10:00", 'in');   // exactly at grace: not late
    punch($employee, "{$this->wednesday} 17:54:00", 'out');  // 6 min early, grace 5: early 6
    $r = $this->processor->process($employee, $this->wednesday);
    expect($r->late_minutes)->toBe(0)->and($r->early_leave_minutes)->toBe(6);

    $two = employeeAt(null);
    assignSchedule($two, WorkScheduleAssignment::query()->first()->schedule);
    punch($two, "{$this->wednesday} 09:11:00", 'in');
    punch($two, "{$this->wednesday} 17:55:00", 'out');
    $r = $this->processor->process($two, $this->wednesday);
    expect($r->late_minutes)->toBe(11)->and($r->early_leave_minutes)->toBe(0);
});

it('keeps historical days on the configuration effective at the time and records the calculation basis', function () {
    $employee = employeeAt(null);
    $old = generalShift(['code' => 'OLD', 'start_time' => '09:00:00', 'end_time' => '18:00:00']);
    $new = generalShift(['code' => 'NEW', 'start_time' => '10:00:00', 'end_time' => '19:00:00']);
    assignSchedule($employee, weeklySchedule($old, ['code' => 'S1']), '2026-01-05');
    assignSchedule($employee, weeklySchedule($new, ['code' => 'S2']), '2026-09-23'); // from Wednesday
    WorkScheduleAssignment::query()->where('employee_id', $employee->id)->where('effective_from', '2026-01-05')->update(['effective_to' => '2026-09-22']);

    punch($employee, '2026-09-22 09:05:00'); // Tuesday on the old shift
    punch($employee, '2026-09-22 18:05:00');
    punch($employee, '2026-09-23 10:05:00'); // Wednesday on the new shift
    punch($employee, '2026-09-23 19:05:00');

    $tue = $this->processor->process($employee, '2026-09-22');
    $wed = $this->processor->process($employee, '2026-09-23');
    expect($tue->shift_id)->toBe($old->id)->and($tue->late_minutes)->toBe(0)
        ->and($wed->shift_id)->toBe($new->id)->and($wed->late_minutes)->toBe(0)
        ->and($tue->calculation_version)->toBe('2.0')
        ->and($tue->calculation_basis['shift_id'])->toBe($old->id)
        ->and($tue->calculation_basis['grace_in'])->toBe(15)
        ->and($tue->calculation_basis['punch_ids'])->toHaveCount(2);

    // Reprocessing Tuesday later still uses the old shift: history does not follow today's configuration.
    $this->travelTo('2026-10-15 10:00:00');
    expect($this->processor->process($employee, '2026-09-22')->shift_id)->toBe($old->id);
});

it('produces the payroll-ready output without any money fields', function () {
    $employee = employeeAt(null);
    assignSchedule($employee, weeklySchedule(generalShift()));
    punch($employee, '2026-09-21 09:00:00');
    punch($employee, '2026-09-21 18:00:00');
    punch($employee, '2026-09-22 09:00:00');
    punch($employee, '2026-09-22 20:30:00'); // overtime 150, pending approval
    $this->processor->processRange($employee, '2026-09-21', '2026-09-23');

    $days = app(AttendanceOutput::class)->forEmployee($employee, '2026-09-21', '2026-09-23');
    expect($days)->toHaveCount(3)
        ->and($days[0]->status)->toBe('present')->and($days[0]->isPaidDay)->toBeTrue()
        ->and($days[1]->overtimeMinutes)->toBe(150)->and($days[1]->approvedOvertimeMinutes)->toBe(0)->and($days[1]->overtimeStatus)->toBe('pending')
        ->and($days[2]->status)->toBe('absent')->and($days[2]->isPaidDay)->toBeFalse();
    expect(array_keys($days[0]->toArray()))->not->toContain('salary', 'earnings', 'amount', 'rate');

    $summary = app(AttendanceOutput::class)->summary($employee, '2026-09-21', '2026-09-23');
    expect($summary['paid_days'])->toBe(2.0)->and($summary['absent_days'])->toBe(1)->and($summary['approved_overtime_minutes'])->toBe(0)->and($summary['scheduled_minutes'])->toBe(1440);
});
