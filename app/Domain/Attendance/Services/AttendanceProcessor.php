<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Events\AttendanceEvent;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\AttendanceRegularisation;
use App\Domain\Attendance\Models\Shift;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use App\Domain\Leave\Services\Leaves;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The attendance engine (§23, §27, §28): punches + shift + holidays + policies -> one record per day.
 * Deterministic and re-runnable; locked records (payroll) are left alone.
 */
final class AttendanceProcessor
{
    public function __construct(
        private readonly ShiftResolver $shifts,
        private readonly HolidayResolver $holidays,
        private readonly PunchIngestion $punches,
        private readonly PolicyResolver $policies,
        private readonly SettingsRepository $settings,
    ) {}

    public function process(Employee $employee, Carbon|string $date): AttendanceRecord
    {
        $date = Carbon::parse($date)->startOfDay();
        $record = AttendanceRecord::query()->firstOrNew(['employee_id' => $employee->id, 'date' => $date]);

        if ($record->exists && $record->is_locked) {
            return $record;
        }

        $resolved = $this->shifts->resolve($employee, $date);
        $shift = $resolved['shift'];
        $holiday = $this->holidays->holidayOn($employee, $date);
        $attendancePolicy = $this->policies->resolve('attendance', $employee, $date);
        $overtimePolicy = $this->policies->resolve('overtime', $employee, $date);
        $regularisations = AttendanceRegularisation::query()->where('employee_id', $employee->id)->whereDate('date', $date->toDateString())->where('status', 'approved')->get();
        $leave = app(Leaves::class)->approvedOn($employee, $date);
        $leaveSession = $leave?->sessionOn($date->toDateString());

        [$windowStart, $windowEnd] = $this->window($shift, $date);
        $punches = $this->punches->punchesBetween($employee, $windowStart, $windowEnd);

        $firstIn = $punches->first()?->punched_at;
        $lastOut = $punches->count() > 1 ? $punches->last()->punched_at : null;

        // Approved regularisations supply or override times.
        foreach ($regularisations as $reg) {
            $firstIn = $reg->requested_in ?? $firstIn;
            $lastOut = $reg->requested_out ?? $lastOut;
        }

        $grace = (int) ($attendancePolicy?->setting('grace_minutes') ?? $shift?->grace_in_minutes ?? 0);
        $breakMinutes = (int) ($shift?->break_minutes ?? 0);

        if ($leaveSession !== null && $leaveSession !== 'full') {
            $breakMinutes = intdiv($breakMinutes, 2); // only half the shift is worked
        }
        $fullDay = (int) ($shift?->full_day_minutes ?? 480);
        $halfDay = (int) ($shift?->half_day_minutes ?? intdiv($fullDay, 2));

        $worked = ($firstIn && $lastOut) ? max(0, (int) $firstIn->diffInMinutes($lastOut) - $breakMinutes) : 0;
        $late = 0;
        $early = 0;
        $exceptions = [];

        if ($shift && ! $shift->isFlexible() && $firstIn && $leaveSession !== 'first_half') {
            $late = max(0, (int) $shift->startsAt($date)->diffInMinutes($firstIn, false) - $grace);
            $late = $late > 0 ? $late + $grace : 0;
        }

        if ($shift && ! $shift->isFlexible() && $lastOut && $leaveSession !== 'second_half') {
            $graceOut = (int) ($shift->grace_out_minutes ?? 0);
            $early = max(0, (int) $lastOut->diffInMinutes($shift->endsAt($date), false) - $graceOut);
            $early = $early > 0 ? $early + $graceOut : 0;
        }

        if ($leaveSession === 'full') {
            $status = $leave->leaveType->is_paid ? 'leave' : 'unpaid_leave';
            $late = $early = 0;
        } else {
            if ($leaveSession !== null) {
                // Half-day leave: the other half is judged against half-day thresholds.
                $fullDay = $halfDay;
                $halfDay = intdiv($halfDay, 2);
            }

            $status = $this->status($resolved, $holiday, $punches, $firstIn, $lastOut, $worked, $fullDay, $halfDay, $regularisations, $exceptions);

            if ($leaveSession !== null && $status === 'absent') {
                $status = $leave->leaveType->is_paid ? 'leave' : 'unpaid_leave';
                $exceptions = [];
            }
        }

        if ($late > 0 && in_array($status, ['present', 'half_day'], true)) {
            $exceptions[] = 'late';
        }

        if ($early > 0 && in_array($status, ['present', 'half_day'], true)) {
            $exceptions[] = 'early_leave';
        }

        $overtime = 0;
        $eligible = (bool) ($shift?->overtime_eligible ?? false) || $overtimePolicy !== null;
        $minOt = (int) ($overtimePolicy?->setting('minimum_minutes') ?? $shift?->min_overtime_minutes ?? 30);

        if ($eligible && $worked > 0) {
            $baseline = in_array($status, ['holiday', 'weekly_off'], true) || (($holiday || $resolved['weekly_off']) && $punches->isNotEmpty()) ? 0 : $fullDay;
            $extra = $worked - $baseline;

            if ($extra >= $minOt) {
                $overtime = $extra;
                $maxWeekly = $overtimePolicy?->setting('max_hours_per_week');
                $exceptions[] = ($overtimePolicy?->setting('approval_required') ?? true) ? 'overtime' : null;

                if ($maxWeekly !== null && $this->weeklyOvertime($employee, $date) + $overtime > (float) $maxWeekly * 60) {
                    $exceptions[] = 'overtime';
                }
            }
        }

        $exceptions = array_values(array_unique(array_filter($exceptions)));
        $previouslyApproved = $record->exists ? $record->overtime_approved_minutes : 0;

        $record->fill([
            'shift_id' => $shift?->id,
            'status' => $status,
            'first_in' => $firstIn,
            'last_out' => $lastOut,
            'worked_minutes' => $worked,
            'late_minutes' => $late,
            'early_leave_minutes' => $early,
            'overtime_minutes' => $overtime,
            'overtime_approved_minutes' => min($previouslyApproved, $overtime),
            'is_regularised' => $regularisations->isNotEmpty(),
            'leave_request_id' => $leave?->id,
            'is_half_day_leave' => $leaveSession !== null && $leaveSession !== 'full',
            'exceptions' => $exceptions === [] ? null : $exceptions,
            'holiday_name' => $holiday?->name,
            'processed_at' => now(),
        ]);
        $record->withAuditReason('Attendance processing')->save();

        if ($exceptions !== []) {
            AttendanceEvent::dispatch('attendance.exception', $employee, $record, ['date' => $date->toDateString(), 'exceptions' => $exceptions, 'status' => $status]);
        }

        if ($overtime > 0) {
            AttendanceEvent::dispatch('attendance.overtime_recorded', $employee, $record, ['date' => $date->toDateString(), 'minutes' => $overtime]);
        }

        return $record;
    }

    /** @return Collection<int, AttendanceRecord> */
    public function processRange(Employee $employee, Carbon|string $from, Carbon|string $to): Collection
    {
        $records = Collection::make();

        for ($day = Carbon::parse($from)->startOfDay(); $day->lte(Carbon::parse($to)); $day->addDay()) {
            $records->push($this->process($employee, $day->copy()));
        }

        return $records;
    }

    /** Every employed employee for one date. */
    public function processAll(Carbon|string $date): int
    {
        $count = 0;

        Employee::query()->with('person')->employed()->orderBy('id')->each(function (Employee $employee) use ($date, &$count) {
            $this->process($employee, $date);
            $count++;
        });

        return $count;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function window(?Shift $shift, Carbon $date): array
    {
        $hours = (int) $this->settings->get('attendance.punch_window_hours', 4);

        if ($shift === null || $shift->isFlexible()) {
            return [$date->copy()->startOfDay(), $date->copy()->endOfDay()];
        }

        return [$shift->startsAt($date)->subHours($hours), $shift->endsAt($date)->addHours($hours)];
    }

    private function status(array $resolved, $holiday, Collection $punches, ?Carbon $firstIn, ?Carbon $lastOut, int $worked, int $fullDay, int $halfDay, Collection $regularisations, array &$exceptions): string
    {
        $special = $regularisations->first(fn ($r) => in_array($r->type, ['wfh', 'on_duty'], true));

        if ($special && $firstIn === null) {
            return $special->type;
        }

        if ($punches->isEmpty() && $firstIn === null) {
            if ($holiday) {
                return 'holiday';
            }

            if ($resolved['weekly_off']) {
                return 'weekly_off';
            }

            if ($resolved['shift'] === null) {
                $exceptions[] = 'no_shift';
            }

            $exceptions[] = 'absent';

            return 'absent';
        }

        if ($firstIn && $lastOut === null) {
            $exceptions[] = 'missed_punch';

            return 'incomplete';
        }

        if ($holiday || $resolved['weekly_off']) {
            $exceptions[] = 'holiday_work';

            return 'present';
        }

        if ($special) {
            return $special->type;
        }

        if ($worked >= $fullDay) {
            return 'present';
        }

        if ($worked >= $halfDay) {
            $exceptions[] = 'short_hours';

            return 'half_day';
        }

        $exceptions[] = 'short_hours';

        return $worked > 0 ? 'half_day' : 'absent';
    }

    private function weeklyOvertime(Employee $employee, Carbon $date): int
    {
        return (int) AttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('date', [$date->copy()->startOfWeek()->toDateString(), $date->copy()->subDay()->toDateString()])
            ->sum('overtime_minutes');
    }
}
