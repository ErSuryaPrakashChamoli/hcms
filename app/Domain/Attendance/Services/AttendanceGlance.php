<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Models\AttendancePunch;
use App\Domain\Employment\Models\Employee;
use Illuminate\Support\Carbon;

/**
 * HROne setup parity CA-6: what a directory card may say about a person's attendance, when the organisation shows it
 * (directory.sections_visible): the time of their last punch, and their coming days off (holidays from their holiday
 * calendar, including chosen restricted holidays, and week-offs from their shift). Times only: never the punch place,
 * device, photos or attendance status. Reads only, with the models' own access scope.
 */
final class AttendanceGlance
{
    public function __construct(
        private readonly HolidayResolver $holidays,
        private readonly ShiftResolver $shifts,
    ) {}

    public function lastCheckIn(Employee $employee): ?Carbon
    {
        $punch = AttendancePunch::query()->where('employee_id', $employee->getKey())->whereNotIn('processing_status', ['failed', 'ignored'])
            ->where('punched_at', '<=', now())->orderByDesc('punched_at')->first(['id', 'punched_at']);

        return $punch?->punched_at;
    }

    /** @return list<array{date: Carbon, kind: string, label: string}> holidays and week-offs from $from for $days days */
    public function offDays(Employee $employee, Carbon $from, int $days = 7): array
    {
        $out = [];
        for ($i = 0; $i < max(0, $days); $i++) {
            $date = $from->copy()->startOfDay()->addDays($i);
            $holiday = $this->holidays->holidayOn($employee, $date);
            if ($holiday !== null) {
                $out[] = ['date' => $date, 'kind' => 'holiday', 'label' => (string) $holiday->name.($holiday->type === 'optional' ? ' (restricted holiday)' : '')];

                continue;
            }
            if ($this->shifts->resolve($employee, $date)['weekly_off']) {
                $out[] = ['date' => $date, 'kind' => 'weekly_off', 'label' => 'Week-off'];
            }
        }

        return $out;
    }
}
