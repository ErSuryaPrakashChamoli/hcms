<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Models\TimeOfficeRequest;
use App\Domain\Employment\Models\Employee;
use Illuminate\Support\Carbon;

/**
 * HROne parity TX-1 (matrix 24 §24.2.2 #7–8, §24.3.2 #12, §24.3.4 #11): read-only facts the detail view of a time-office
 * request or a regularisation shows next to it — the shift before and after a shift / week-off change, and the day's
 * current status (processed record, else weekly off / holiday from the resolvers). Nothing here changes a day.
 */
final class TimeOfficeDetails
{
    /** Days listed at most for one request (a shift change covers at most two months). */
    public const MAX_DAYS = 62;

    public function __construct(
        private readonly ShiftResolver $shifts,
        private readonly HolidayResolver $holidays,
    ) {}

    /**
     * Earlier and new shift for each day of a shift change or week-off change. Once the request was applied, the
     * earlier shift is what the request replaced (kept in `effects`); before that it is the shift the day resolves to now.
     *
     * @return list<array{date: string, earlier: string, new: string}>
     */
    public function shiftChanges(TimeOfficeRequest $request): array
    {
        if (! in_array($request->type, ['shift_change', 'week_off_change'], true) || $request->from_date === null) {
            return [];
        }
        $request->loadMissing(['employee', 'shift']);
        $employee = $request->employee;
        if ($employee === null) {
            return [];
        }
        $new = [];
        $to = $request->to_date ?? $request->from_date;
        for ($day = $request->from_date->copy(); $day->lte($to) && count($new) < self::MAX_DAYS; $day->addDay()) {
            $new[$day->toDateString()] = $request->shift_id;
        }
        if ($request->type === 'week_off_change' && $request->swap_date !== null) {
            $new[$request->swap_date->toDateString()] = null;
        }
        $applied = (array) (($request->effects ?? [])['previous_overrides'] ?? []);
        $names = Shift::query()->whereKey(array_filter([...array_values($new), ...array_column(array_filter($applied, 'is_array'), 'shift_id')]))->pluck('name', 'id');
        $label = fn (?int $shiftId) => $shiftId === null ? 'Weekly off' : (string) ($names[$shiftId] ?? 'Shift #'.$shiftId);

        $rows = [];
        foreach ($new as $date => $shiftId) {
            if (array_key_exists($date, $applied)) {
                $previous = $applied[$date];
                $earlier = is_array($previous) ? $label(isset($previous['shift_id']) ? (int) $previous['shift_id'] : null) : $this->scheduledLabel($employee, Carbon::parse($date));
            } else {
                $resolved = $this->shifts->resolve($employee, Carbon::parse($date));
                $earlier = $resolved['shift'] !== null ? $resolved['shift']->name : ($resolved['weekly_off'] ? 'Weekly off' : 'No shift');
            }
            $rows[] = ['date' => $date, 'earlier' => $earlier, 'new' => $label($shiftId === null ? null : (int) $shiftId)];
        }

        return $rows;
    }

    /**
     * The day as it stands now: the processed attendance record when there is one, else what the schedule and the
     * holiday calendar say.
     *
     * @return array{label: string, detail: ?string, locked: bool}
     */
    public function dayStatus(Employee $employee, Carbon|string $date): array
    {
        $day = Carbon::parse($date)->startOfDay();
        $record = AttendanceRecord::query()->with('shift')->where('employee_id', $employee->id)->whereDate('date', $day->toDateString())->first();
        if ($record !== null) {
            $parts = array_filter([
                $record->shift?->name,
                $record->first_in ? 'in '.$record->first_in->format('H:i') : null,
                $record->last_out ? 'out '.$record->last_out->format('H:i') : null,
                $record->holiday_name ? 'holiday: '.$record->holiday_name : null,
                $record->leave_request_id !== null ? ($record->is_half_day_leave ? 'half-day leave' : 'on leave') : null,
            ]);

            return [
                'label' => (string) config("peopleos.attendance.statuses.{$record->status}", $record->status),
                'detail' => $parts === [] ? null : implode(' · ', $parts),
                'locked' => (bool) $record->is_locked,
            ];
        }
        $holiday = $this->holidays->holidayOn($employee, $day->copy());
        $resolved = $this->shifts->resolve($employee, $day->copy());
        $label = match (true) {
            $holiday !== null => 'Holiday',
            $resolved['weekly_off'] => 'Weekly off',
            $resolved['shift'] !== null => 'Working day',
            default => 'No shift',
        };

        return ['label' => $label.' (not processed yet)', 'detail' => $holiday?->name ?? $resolved['shift']?->name, 'locked' => false];
    }

    /** The shift of the employee's schedule on the day, ignoring any rostered day. */
    private function scheduledLabel(Employee $employee, Carbon $date): string
    {
        [$schedule, $anchor] = $this->shifts->scheduleFor($employee, $date);
        if ($schedule === null) {
            return 'No shift';
        }
        $shiftId = $schedule->shiftIdFor($date, $anchor);

        return $shiftId === null ? 'Weekly off' : (string) (Shift::query()->whereKey($shiftId)->value('name') ?? 'Shift #'.$shiftId);
    }
}
