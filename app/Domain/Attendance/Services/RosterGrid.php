<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Contracts\LeaveDayResolver;
use App\Domain\Attendance\Contracts\PendingLeaveReader;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Models\ShiftOverride;
use App\Domain\Employment\Models\Employee;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * HROne parity TX-5 (matrix 24 §24.2.1 #1, the roster board): what each day of a week or a month looks like for a page of
 * employees — the shift ShiftResolver resolves (the rostered day first, then the schedule), whether the day is rostered
 * (by whom, from where, with automatic shift correction), and the marks around it: weekly off, holiday (HolidayResolver),
 * approved leave (LeaveDayResolver) and leave still waiting for a decision (PendingLeaveReader) — leave is read only
 * through those contracts —, locked days, days of a closed attendance month, the day's processed status, and days the
 * employee is not employed on. Read only, in the viewer's access scope, in a few reads per page.
 */
final class RosterGrid
{
    public function __construct(
        private readonly ShiftResolver $shifts,
        private readonly HolidayResolver $holidays,
        private readonly LeaveDayResolver $leave,
        private readonly PendingLeaveReader $pending,
        private readonly AttendanceMonthCloses $closes,
    ) {}

    /** Is the employee employed on the day (past pre-boarding, joined, not gone)? The roster changes only these days. */
    public static function employedOn(Employee $employee, CarbonInterface $day): bool
    {
        return in_array($employee->lifecycle_state?->value, AttendanceMonthPopulation::states(), true)
            && ($employee->joining_date === null || $employee->joining_date->lte($day))
            && ($employee->exit_date === null || $employee->exit_date->gte($day));
    }

    /** "09:00–18:00" (flexible shifts: "flexible"). */
    public static function times(?Shift $shift): ?string
    {
        if ($shift === null) {
            return null;
        }

        return $shift->start_time === null || $shift->end_time === null ? 'flexible' : substr((string) $shift->start_time, 0, 5).'–'.substr((string) $shift->end_time, 0, 5);
    }

    /**
     * @param  iterable<Employee>  $employees
     * @return array<int, array<string, array<string, mixed>>> employee id => Y-m-d => the day's cell
     */
    public function cells(iterable $employees, CarbonInterface $from, CarbonInterface $to): array
    {
        /** @var Collection<int, Employee> $employees */
        $employees = collect($employees)->keyBy(fn (Employee $e) => (int) $e->id);
        $from = Carbon::parse($from->toDateString());
        $to = Carbon::parse($to->toDateString());
        if ($employees->isEmpty() || $to->lt($from)) {
            return [];
        }
        $ids = $employees->keys()->all();
        $resolved = $this->shifts->resolveBetween($employees, $from, $to);
        $records = AttendanceRecord::query()->whereIn('employee_id', $ids)->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())
            ->get(['id', 'employee_id', 'date', 'status', 'shift_id', 'is_locked', 'calculation_basis'])
            ->groupBy(fn (AttendanceRecord $r) => (int) $r->employee_id)->map(fn (Collection $rows) => $rows->keyBy(fn (AttendanceRecord $r) => $r->date->toDateString()));
        $pending = [];
        foreach ($this->pending->pendingBetween($ids, $from, $to) as $request) {
            $day = Carbon::parse($request['from']);
            $day = $day->lt($from) ? $from->copy() : $day;
            $last = Carbon::parse($request['to']);
            for ($last = $last->gt($to) ? $to->copy() : $last; $day->lte($last); $day->addDay()) {
                $pending[(int) $request['employee_id']][$day->toDateString()] = $request['label'];
            }
        }
        $codes = Shift::query()->pluck('code', 'id');

        $out = [];
        foreach ($employees as $id => $employee) {
            $leave = $this->leave->approvedLeaveBetween($employee, $from->copy(), $to->copy());
            $holidays = $this->holidaysBetween($employee, $from, $to);
            $closed = array_flip($this->closes->closedDates($employee, $from, $to));
            for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
                $date = $day->toDateString();
                $answer = $resolved[$id][$date] ?? ['schedule' => null, 'shift' => null, 'weekly_off' => false, 'anchor' => null];
                /** @var ?Shift $shift */
                $shift = $answer['shift'];
                /** @var ?ShiftOverride $override */
                $override = $answer['override'] ?? null;
                $record = $records[$id][$date] ?? null;
                $leaveDay = $leave[$date] ?? null;
                $processedShift = $record !== null && $record->shift_id !== null && (int) $record->shift_id !== (int) $shift?->id ? ($codes[(int) $record->shift_id] ?? null) : null;
                $out[$id][$date] = [
                    'date' => $date,
                    'active' => self::employedOn($employee, $day),
                    'shift_id' => $shift?->id,
                    'code' => $shift?->code,
                    'name' => $shift?->name,
                    'times' => self::times($shift),
                    'colour' => $shift?->colour,
                    'weekly_off' => (bool) $answer['weekly_off'],
                    'no_schedule' => $shift === null && ! $answer['weekly_off'],
                    'schedule' => $answer['schedule']?->name,
                    'rostered' => $override !== null,
                    'source' => $override !== null ? (ShiftOverride::SOURCES[$override->source] ?? $override->source) : null,
                    'roster_reason' => $override?->reason,
                    'auto_shift' => ! empty($answer['auto_shift']),
                    'schedule_auto' => $override === null && array_filter((array) ($answer['schedule']?->auto_shift_ids ?? [])) !== []
                        && (! $answer['weekly_off'] || (bool) $answer['schedule']?->auto_shift_on_off_days),
                    'holiday' => isset($holidays[$date]) ? $holidays[$date]->name : null,
                    'leave' => $leaveDay === null ? null : trim(($leaveDay->leaveTypeCode ?? 'Leave').' '.($leaveDay->isFullDay() ? '' : ($leaveDay->session === 'first_half' ? '(first half)' : '(second half)'))),
                    'leave_paid' => $leaveDay?->isPaid,
                    'leave_pending' => $pending[$id][$date] ?? null,
                    'locked' => (bool) $record?->is_locked,
                    'closed' => isset($closed[$date]),
                    'status' => $record?->status,
                    'processed_shift' => $processedShift,
                ];
            }
        }

        return $out;
    }

    /**
     * Holidays of the range for the employee: the calendar's holidays at the range's ends are the candidates, and each
     * candidate day is confirmed by HolidayResolver::holidayOn (restricted holidays only when chosen), so the board shows
     * exactly what processing sees.
     *
     * @return array<string, Holiday> Y-m-d => holiday
     */
    private function holidaysBetween(Employee $employee, Carbon $from, Carbon $to): array
    {
        $calendars = collect([$this->holidays->calendarFor($employee, $from->copy()), $this->holidays->calendarFor($employee, $to->copy())])->filter()->unique('id');
        // HROne parity TX-12: days HR changed for the employee are candidates too (a holiday given off the calendar).
        $changed = app(EmployeeHolidayChanges::class)->liveBetween($employee, $from->copy(), $to->copy())->keys();
        if ($calendars->isEmpty() && $changed->isEmpty()) {
            return [];
        }
        $dates = ($calendars->isEmpty() ? collect() : Holiday::query()->whereIn('holiday_calendar_id', $calendars->pluck('id'))->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())
            ->pluck('date')->map(fn ($d) => Carbon::parse($d)->toDateString()))->merge($changed)->unique()->sort()->values();
        $out = [];
        foreach ($dates as $date) {
            if (($holiday = $this->holidays->holidayOn($employee, Carbon::parse($date))) !== null) {
                $out[$date] = $holiday;
            }
        }

        return $out;
    }
}
