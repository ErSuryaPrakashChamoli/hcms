<?php

use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Models\WorkSchedule;
use App\Domain\Attendance\Models\WorkScheduleAssignment;
use App\Domain\Attendance\Services\PunchIngestion;
use App\Domain\Employment\Models\Employee;

function generalShift(array $overrides = []): Shift
{
    return Shift::create(array_merge([
        'name' => 'General', 'code' => 'GEN', 'type' => 'fixed', 'start_time' => '09:00:00', 'end_time' => '18:00:00',
        'break_minutes' => 60, 'full_day_minutes' => 480, 'half_day_minutes' => 240, 'grace_in_minutes' => 15, 'grace_out_minutes' => 0,
        'overtime_eligible' => true, 'min_overtime_minutes' => 30,
    ], $overrides));
}

function weeklySchedule(Shift $shift, array $overrides = []): WorkSchedule
{
    return WorkSchedule::create(array_merge([
        'name' => 'Mon–Fri', 'code' => 'MF', 'effective_from' => '2026-01-05',
        'pattern' => [['mon' => $shift->id, 'tue' => $shift->id, 'wed' => $shift->id, 'thu' => $shift->id, 'fri' => $shift->id, 'sat' => null, 'sun' => null]],
    ], $overrides));
}

function assignSchedule(Employee $employee, WorkSchedule $schedule, string $from = '2026-01-05'): WorkScheduleAssignment
{
    return WorkScheduleAssignment::create(['employee_id' => $employee->id, 'work_schedule_id' => $schedule->id, 'effective_from' => $from]);
}

function punch(Employee $employee, string $at, string $direction = 'auto'): void
{
    app(PunchIngestion::class)->record($employee, $at, $direction, 'web');
}
