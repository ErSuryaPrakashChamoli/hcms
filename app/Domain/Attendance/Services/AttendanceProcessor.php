<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Contracts\LeaveDayResolver;
use App\Domain\Attendance\Events\AttendanceEvent;
use App\Domain\Attendance\Models\AttendancePunch;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\AttendanceRegularisation;
use App\Domain\Attendance\Models\Shift;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The attendance calculation engine (Phase 2 §16–§22). Deterministic: the same punches, shift,
 * schedule, holiday, policies, regularisations and leave answer produce the same record. Raw
 * punches are never modified; the record stores the basis it was calculated from.
 */
final class AttendanceProcessor
{
    public const SPECIAL_TYPES = ['wfh', 'on_duty', 'field_duty'];

    public function __construct(
        private readonly ShiftResolver $shifts,
        private readonly HolidayResolver $holidays,
        private readonly PunchIngestion $punches,
        private readonly PolicyResolver $policies,
        private readonly SettingsRepository $settings,
        private readonly LeaveDayResolver $leave,
        private readonly AttendanceTimezone $timezones,
    ) {}

    public function process(Employee $employee, Carbon|string $date): AttendanceRecord
    {
        $date = Carbon::parse($date)->startOfDay();

        return DB::transaction(function () use ($employee, $date) {
            $record = AttendanceRecord::query()->where('employee_id', $employee->id)->whereDate('date', $date->toDateString())->lockForUpdate()->first()
                ?? new AttendanceRecord(['employee_id' => $employee->id, 'date' => $date]);

            if ($record->exists && $record->is_locked) {
                return $record;
            }

            $previousStatus = $record->exists ? $record->status : null;

            $resolved = $this->shifts->resolve($employee, $date);
            /** @var ?Shift $shift */
            $shift = $resolved['shift'];
            $shift?->loadMissing('breaks');
            $timezone = $this->timezones->for($employee, $shift, $date);
            $holiday = $this->holidays->holidayOn($employee, $date);
            $attendancePolicy = $this->policies->resolve('attendance', $employee, $date);
            $overtimePolicy = $this->policies->resolve('overtime', $employee, $date);
            $regularisations = AttendanceRegularisation::query()->where('employee_id', $employee->id)->whereDate('date', $date->toDateString())->where('status', 'approved')->orderBy('id')->get();
            $leave = $this->leave->approvedLeaveOn($employee, $date);

            $scheduledStart = $shift?->startsAt($date, $timezone);
            $scheduledEnd = $shift?->endsAt($date, $timezone);
            [$windowStart, $windowEnd] = $this->window($shift, $date, $timezone);
            $punches = $this->punches->punchesBetween($employee, $windowStart, $windowEnd);

            $exceptions = [];
            [$firstIn, $lastOut] = $this->resolveInOut($punches, $exceptions);

            // Approved regularisations supply or override times; the evidence stays untouched.
            foreach ($regularisations as $reg) {
                $firstIn = $reg->requested_in ?? $firstIn;
                $lastOut = $reg->requested_out ?? $lastOut;
            }
            if ($regularisations->isNotEmpty()) {
                $exceptions = array_values(array_diff($exceptions, ['missing_in', 'missing_out', 'invalid_sequence']));
            }

            $graceIn = (int) ($attendancePolicy?->setting('grace_minutes') ?? $shift?->grace_in_minutes ?? 0);
            $graceOut = (int) ($shift?->grace_out_minutes ?? 0);
            $breakMinutes = $shift?->unpaidBreakMinutes() ?? 0;
            $fullDay = (int) ($shift?->full_day_minutes ?? 480);
            $halfDay = (int) ($shift?->half_day_minutes ?? intdiv($fullDay, 2));
            $scheduledMinutes = $shift ? ($shift->isFlexible() ? $fullDay : max(0, (int) $scheduledStart->diffInMinutes($scheduledEnd) - $breakMinutes)) : 0;

            if ($leave !== null && ! $leave->isFullDay()) {
                // Only half the shift is worked: half the break, half the expectation.
                $breakMinutes = intdiv($breakMinutes, 2);
                $scheduledMinutes = intdiv($scheduledMinutes, 2);
                $fullDay = $halfDay;
                $halfDay = intdiv($halfDay, 2);
            }

            $worked = ($firstIn && $lastOut) ? max(0, (int) $firstIn->diffInMinutes($lastOut) - $breakMinutes) : 0;
            $late = 0;
            $early = 0;

            if ($shift && ! $shift->isFlexible() && $firstIn && $leave?->session !== 'first_half') {
                $late = max(0, (int) $scheduledStart->diffInMinutes($firstIn, false) - $graceIn);
                $late = $late > 0 ? $late + $graceIn : 0;
            }

            if ($shift && ! $shift->isFlexible() && $lastOut && $leave?->session !== 'second_half') {
                $early = max(0, (int) $lastOut->diffInMinutes($scheduledEnd, false) - $graceOut);
                $early = $early > 0 ? $early + $graceOut : 0;
            }

            if ($leave?->isFullDay()) {
                $status = $leave->isPaid ? 'leave' : 'unpaid_leave';
                $late = $early = 0;
                $exceptions = [];
            } else {
                $status = $this->status($resolved, $holiday, $punches, $firstIn, $lastOut, $worked, $fullDay, $halfDay, $regularisations, $exceptions);

                if ($leave !== null && $status === 'absent') {
                    $status = $leave->isPaid ? 'leave' : 'unpaid_leave';
                    $exceptions = [];
                }
            }

            if ($late > 0 && in_array($status, ['present', 'half_day'], true)) {
                $exceptions[] = 'late';
            }
            if ($early > 0 && in_array($status, ['present', 'half_day'], true)) {
                $exceptions[] = 'early_leave';
            }

            [$overtime, $overtimeStatus] = $this->overtime($employee, $date, $shift, $overtimePolicy, $status, $holiday, $resolved, $punches, $worked, $fullDay, $record, $exceptions);

            $exceptions = array_values(array_unique(array_filter($exceptions)));
            $previouslyApproved = $record->exists ? (int) $record->overtime_approved_minutes : 0;

            $record->fill([
                'shift_id' => $shift?->id,
                'scheduled_start' => $scheduledStart,
                'scheduled_end' => $scheduledEnd,
                'scheduled_minutes' => $scheduledMinutes,
                'status' => $status,
                'first_in' => $firstIn,
                'last_out' => $lastOut,
                'worked_minutes' => $worked,
                'break_minutes' => ($firstIn && $lastOut) ? $breakMinutes : 0,
                'late_minutes' => $late,
                'early_leave_minutes' => $early,
                'overtime_minutes' => $overtime,
                'overtime_approved_minutes' => min($previouslyApproved, $overtime),
                'overtime_status' => $overtimeStatus,
                'is_regularised' => $regularisations->isNotEmpty(),
                'leave_request_id' => $leave?->leaveRequestId,
                'is_half_day_leave' => $leave !== null && ! $leave->isFullDay(),
                'exceptions' => $exceptions === [] ? null : $exceptions,
                'holiday_name' => $holiday?->name,
                'timezone' => $timezone,
                'calculation_version' => (string) config('peopleos.attendance.engine_version', '2.0'),
                'calculation_basis' => [
                    'engine' => (string) config('peopleos.attendance.engine_version', '2.0'),
                    'schedule_id' => $resolved['schedule']?->id, 'shift_id' => $shift?->id, 'timezone' => $timezone,
                    'attendance_policy_version_id' => $attendancePolicy?->id, 'overtime_policy_version_id' => $overtimePolicy?->id,
                    'holiday_id' => $holiday?->id, 'grace_in' => $graceIn, 'grace_out' => $graceOut, 'unpaid_break_minutes' => $breakMinutes,
                    'full_day_minutes' => $fullDay, 'half_day_minutes' => $halfDay, 'punch_ids' => $punches->pluck('id')->all(),
                    'regularisation_ids' => $regularisations->pluck('id')->all(), 'leave_request_id' => $leave?->leaveRequestId,
                    'window' => [$windowStart->toIso8601String(), $windowEnd->toIso8601String()],
                ],
                'processed_at' => now(),
            ]);
            $record->withAuditReason('Attendance processing')->save();

            if ($punches->isNotEmpty()) {
                AttendancePunch::query()->whereIn('id', $punches->pluck('id'))->update(['processing_status' => 'processed', 'processing_error' => null, 'processed_at' => now()]);
            }

            AttendanceEvent::dispatch('attendance.processed', $employee, $record, ['date' => $date->toDateString(), 'status' => $status, 'worked_minutes' => $worked]);
            if ($previousStatus !== null && $previousStatus !== $status) {
                AttendanceEvent::dispatch('attendance.status_changed', $employee, $record, ['date' => $date->toDateString(), 'from' => $previousStatus, 'to' => $status]);
            }
            if ($exceptions !== []) {
                AttendanceEvent::dispatch('attendance.exception', $employee, $record, ['date' => $date->toDateString(), 'exceptions' => $exceptions, 'status' => $status]);
            }
            if ($overtime > 0) {
                AttendanceEvent::dispatch('attendance.overtime_recorded', $employee, $record, ['date' => $date->toDateString(), 'minutes' => $overtime]);
            }

            return $record;
        });
    }

    /** @return Collection<int, AttendanceRecord> */
    public function processRange(Employee $employee, Carbon|string $from, Carbon|string $to): Collection
    {
        $records = collect();
        for ($day = Carbon::parse($from)->startOfDay(); $day->lte(Carbon::parse($to)); $day->addDay()) {
            $records->push($this->process($employee, $day->copy()));
        }

        return $records;
    }

    public function processAll(Carbon|string $date): int
    {
        $count = 0;
        Employee::query()->with('person')->employed()->orderBy('id')->chunkById(200, function ($employees) use ($date, &$count) {
            foreach ($employees as $employee) {
                $this->process($employee, $date);
                $count++;
            }
        });

        return $count;
    }

    /** The punch window of an employee's work date (used to attribute a punch to its day). @return array{0: Carbon, 1: Carbon} */
    public function windowFor(Employee $employee, Carbon $date): array
    {
        $shift = $this->shifts->resolve($employee, $date)['shift'];

        return $this->window($shift, $date, $this->timezones->for($employee, $shift, $date));
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function window(?Shift $shift, Carbon $date, string $timezone): array
    {
        $hours = (int) $this->settings->get('attendance.punch_window_hours', 4);

        if ($shift === null || $shift->isFlexible()) {
            $start = Carbon::parse($date->toDateString().' 00:00:00', $timezone)->setTimezone(config('app.timezone'));

            return [$start, $start->copy()->addDay()->subSecond()];
        }

        return [$shift->startsAt($date, $timezone)->subHours($hours), $shift->endsAt($date, $timezone)->addHours($hours)];
    }

    /**
     * First valid IN and last valid OUT from whatever the sources reported (Phase 2 §19): directed
     * punches win over undirected ones; a lone punch, a missing IN or OUT and reversed sequences
     * become exceptions instead of corrupting the day.
     *
     * @param  Collection<int, AttendancePunch>  $punches
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function resolveInOut(Collection $punches, array &$exceptions): array
    {
        if ($punches->isEmpty()) {
            return [null, null];
        }

        $ins = $punches->where('direction', 'in');
        $outs = $punches->where('direction', 'out');
        $undirected = $punches->where('direction', 'auto');

        $firstIn = $ins->first()?->punched_at ?? ($undirected->first()?->punched_at ?? null);
        $lastOut = $outs->last()?->punched_at ?? ($undirected->count() > 1 ? $undirected->last()->punched_at : null);

        if ($ins->isNotEmpty() && $outs->isEmpty() && $undirected->count() <= 1) {
            $exceptions[] = 'missing_out';
            $lastOut = null;
        } elseif ($outs->isNotEmpty() && $ins->isEmpty() && $undirected->isEmpty()) {
            $exceptions[] = 'missing_in';
            $firstIn = null;
        } elseif ($punches->count() === 1) {
            $exceptions[] = $punches->first()->direction === 'out' ? 'missing_in' : 'missing_out';
            $lastOut = null;
        }

        if ($firstIn && $lastOut && $lastOut->lt($firstIn)) {
            $exceptions[] = 'invalid_sequence';
            $lastOut = null;
        }

        return [$firstIn, $lastOut];
    }

    private function status(array $resolved, $holiday, Collection $punches, ?Carbon $firstIn, ?Carbon $lastOut, int $worked, int $fullDay, int $halfDay, Collection $regularisations, array &$exceptions): string
    {
        $special = $regularisations->first(fn ($r) => in_array($r->type, self::SPECIAL_TYPES, true));

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

        if (($firstIn && $lastOut === null) || ($firstIn === null && $punches->isNotEmpty())) {
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

        $exceptions[] = 'short_hours';

        if ($worked >= $halfDay) {
            return 'half_day';
        }

        return $worked > 0 ? 'half_day' : 'absent';
    }

    /** @return array{0: int, 1: string} calculated overtime minutes and its review status */
    private function overtime(Employee $employee, Carbon $date, ?Shift $shift, $overtimePolicy, string $status, $holiday, array $resolved, Collection $punches, int $worked, int $fullDay, AttendanceRecord $record, array &$exceptions): array
    {
        $eligible = (bool) ($shift?->overtime_eligible ?? false) || $overtimePolicy !== null;
        if (! $eligible || ! in_array($status, ['present', 'half_day', 'holiday', 'weekly_off'], true)) {
            return [0, 'none'];
        }

        $minOt = (int) ($overtimePolicy?->setting('minimum_minutes') ?? $shift?->min_overtime_minutes ?? 30);
        $baseline = in_array($status, ['holiday', 'weekly_off'], true) || (($holiday || $resolved['weekly_off']) && $punches->isNotEmpty()) ? 0 : $fullDay;
        $extra = max(0, $worked - $baseline);

        if ($extra < $minOt) {
            return [0, 'none'];
        }

        $rounding = (int) ($overtimePolicy?->setting('rounding_minutes') ?? 0);
        if ($rounding > 0) {
            $extra = intdiv($extra, $rounding) * $rounding;
        }
        $maxDaily = $overtimePolicy?->setting('max_daily_minutes');
        if ($maxDaily !== null && (int) $maxDaily > 0) {
            $extra = min($extra, (int) $maxDaily);
        }

        $approvalRequired = (bool) ($overtimePolicy?->setting('approval_required') ?? true);
        $maxWeekly = $overtimePolicy?->setting('max_hours_per_week');
        if ($maxWeekly !== null && $this->weeklyOvertime($employee, $date) + $extra > (float) $maxWeekly * 60) {
            $approvalRequired = true;
        }

        if ($approvalRequired) {
            $exceptions[] = 'overtime';
        }

        // Keep a previous review decision when the calculated quantity did not change.
        $previous = $record->exists && (int) $record->overtime_minutes === $extra ? (string) $record->overtime_status : null;
        $status = in_array($previous, ['approved', 'rejected'], true) ? $previous : ($approvalRequired ? 'pending' : 'approved');
        if ($status === 'approved' && $previous !== 'approved' && ! $approvalRequired) {
            $record->overtime_approved_minutes = $extra;
        }

        return [$extra, $status];
    }

    private function weeklyOvertime(Employee $employee, Carbon $date): int
    {
        return (int) AttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('date', [$date->copy()->startOfWeek()->toDateString(), $date->copy()->subDay()->toDateString()])
            ->sum('overtime_minutes');
    }
}
