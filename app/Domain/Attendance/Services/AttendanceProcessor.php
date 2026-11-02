<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Contracts\LeaveDay;
use App\Domain\Attendance\Contracts\LeaveDayResolver;
use App\Domain\Attendance\Events\AttendanceEvent;
use App\Domain\Attendance\Models\AttendanceArrear;
use App\Domain\Attendance\Models\AttendanceMark;
use App\Domain\Attendance\Models\AttendanceOverride;
use App\Domain\Attendance\Models\AttendancePunch;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\AttendanceRegularisation;
use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Models\TimeOfficeRequest;
use App\Domain\Attendance\Support\AttendanceDay;
use App\Domain\Attendance\Support\RequestRules;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Services\Entitlements;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The attendance calculation engine (Phase 2 §16–§22). Deterministic: the same punches, shift,
 * schedule, holiday, policies, regularisations, leave answer and uploaded mark produce the same
 * record. Raw punches are never modified; the record stores the basis it was calculated from.
 * Precedence for the status: approved full-day leave, then an HR override (HP-1), then a mark from
 * a monthly attendance upload, then punches, regularisations and approved time-office requests
 * (on duty, short leave, overtime claims). A day locked by payroll is never rewritten: when
 * corrections after lock are allowed, the difference becomes an attendance arrear instead.
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
        // SaaS.3: shadow entitlement observation (never blocks; see Entitlements).
        app(Entitlements::class)->observe(Capability::Attendance, 'attendance.day.process');
        $date = Carbon::parse($date)->startOfDay();

        return DB::transaction(function () use ($employee, $date) {
            $record = AttendanceRecord::query()->where('employee_id', $employee->id)->whereDate('date', $date->toDateString())->lockForUpdate()->first()
                ?? new AttendanceRecord(['employee_id' => $employee->id, 'date' => $date]);

            // HROne parity TX-3: a day of a closed attendance month keeps its values like a locked day (month close is off by default).
            if ($record->exists && ($record->is_locked || app(AttendanceMonthCloses::class)->isClosedDay($employee, $date))) {
                $this->recordArrear($employee, $date, $record);

                return $record;
            }

            $previousStatus = $record->exists ? $record->status : null;
            ['status' => $status, 'worked' => $worked, 'overtime' => $overtime, 'exceptions' => $exceptions, 'punches' => $punches] = $this->calculate($employee, $date, $record);
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

    /**
     * Work out the day and fill (not save) the record.
     *
     * @return array{status: string, worked: int, overtime: int, exceptions: list<string>, punches: Collection<int, AttendancePunch>}
     */
    private function calculate(Employee $employee, Carbon $date, AttendanceRecord $record): array
    {

        $resolved = $this->shifts->resolve($employee, $date);
        /** @var ?Shift $shift */
        $shift = $resolved['shift'];
        // HROne setup parity (TO-5): automatic shift correction — the schedule's shift whose start is nearest the first punch.
        $autoShift = $this->autoShift($employee, $date, $resolved);
        if ($autoShift !== null) {
            $shift = $autoShift;
        }
        $shift?->loadMissing('breaks');
        $timezone = $this->timezones->for($employee, $shift, $date);
        // HROne parity TX-12: HR's holiday change of the employee's day wins over the calendar (none by default).
        ['holiday' => $holiday, 'change' => $holidayChange] = $this->holidays->dayOn($employee, $date);
        $attendancePolicy = $this->policies->resolve('attendance', $employee, $date);
        $overtimePolicy = $this->policies->resolve('overtime', $employee, $date);
        $regularisations = AttendanceRegularisation::query()->where('employee_id', $employee->id)->whereDate('date', $date->toDateString())->where('status', 'approved')->orderBy('id')->get();
        $leave = $this->leave->approvedLeaveOn($employee, $date);
        $mark = AttendanceMark::query()->where('employee_id', $employee->id)->whereDate('date', $date->toDateString())->first();
        // HP-1: approved time-office requests covering the day, and an HR override of the day.
        $requests = TimeOfficeRequest::query()->where('employee_id', $employee->id)->whereIn('type', ['short_leave', 'on_duty', 'overtime'])->effective()->covering($date)->orderBy('id')->get();
        $override = AttendanceOverride::query()->where('employee_id', $employee->id)->whereDate('date', $date->toDateString())->first();
        $onDutyDay = $requests->first(fn (TimeOfficeRequest $r) => $r->type === 'on_duty' && $r->session === 'full');
        $onDutyHalf = $requests->first(fn (TimeOfficeRequest $r) => $r->type === 'on_duty' && in_array($r->session, ['first_half', 'second_half'], true));
        $shortLeaveMinutes = (int) $requests->where('type', 'short_leave')->sum(fn (TimeOfficeRequest $r) => $r->spanMinutes());
        $onDutyMinutes = (int) $requests->filter(fn (TimeOfficeRequest $r) => $r->type === 'on_duty' && $r->session === 'hours')->sum(fn (TimeOfficeRequest $r) => $r->spanMinutes());
        $claimedOvertime = (int) $requests->where('type', 'overtime')->sum('minutes');
        // A half day on duty is the half the employee is away, like half-day leave.
        $awaySession = $leave?->session ?? $onDutyHalf?->session;

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

        if (($leave !== null && ! $leave->isFullDay()) || $onDutyHalf !== null) {
            // Only half the shift is worked: half the break, half the expectation.
            $breakMinutes = intdiv($breakMinutes, 2);
            $scheduledMinutes = intdiv($scheduledMinutes, 2);
            $fullDay = $halfDay;
            $halfDay = intdiv($halfDay, 2);
        }

        // HROne setup parity (TO-4): punch mode and counting only within the shift.
        $punchMode = (string) ($attendancePolicy?->setting('punch_mode') ?? 'double');
        $workingDay = $shift !== null && ! $resolved['weekly_off'] && $holiday === null;
        if ($punchMode === 'single' && $workingDay && $punches->isNotEmpty() && ($firstIn === null || $lastOut === null) && $regularisations->isEmpty()) {
            // One punch is the whole shift.
            [$firstIn, $lastOut] = [$scheduledStart->copy(), $scheduledEnd->copy()];
            $exceptions = array_values(array_diff($exceptions, ['missing_in', 'missing_out', 'invalid_sequence']));
        } elseif (in_array($punchMode, ['auto', 'none'], true) && $workingDay && $punches->isEmpty() && $firstIn === null && $lastOut === null) {
            // Present by default: the shift counts as worked (leave, on duty and overrides still decide the day).
            [$firstIn, $lastOut] = [$scheduledStart->copy(), $scheduledEnd->copy()];
        }
        [$countFrom, $countTo] = [$firstIn, $lastOut];
        if (RequestRules::isYes($attendancePolicy?->setting('count_within_shift')) && $shift && ! $shift->isFlexible() && $firstIn && $lastOut) {
            $countFrom = $firstIn->copy()->max($scheduledStart);
            $countTo = $lastOut->copy()->min($scheduledEnd);
        }
        // HROne setup parity (TO-5): the shift may not count time before its start, or after a cut-off time.
        if ($shift && ! $shift->isFlexible() && $countFrom && $countTo) {
            if ($shift->ignore_before_start) {
                $countFrom = $countFrom->copy()->max($scheduledStart);
            }
            if ($shift->cutoff_time) {
                $cutoff = Carbon::parse($date->toDateString().' '.$shift->cutoff_time, $timezone)->setTimezone(config('app.timezone'));
                if ($cutoff->lt($scheduledStart)) {
                    $cutoff->addDay(); // a night shift's cut-off falls the next morning
                }
                $countTo = $countTo->copy()->min($cutoff);
            }
        }

        // Hourly on duty counts as work; short leave lowers the full-day requirement without counting as work.
        $worked = (($countFrom && $countTo && $countTo->gt($countFrom)) ? max(0, (int) $countFrom->diffInMinutes($countTo) - $breakMinutes) : 0) + $onDutyMinutes;
        $overtimeBaseline = $fullDay; // short leave lowers what counts as a full day, never the overtime baseline
        $fullDay = max(0, $fullDay - $shortLeaveMinutes);
        $halfDay = min($halfDay, $fullDay);
        $late = 0;
        $early = 0;

        $flexi = ($attendancePolicy?->setting('timing') ?? 'strict') === 'flexi';
        if ($shift && ! $shift->isFlexible() && ! $flexi && $firstIn && $awaySession !== 'first_half') {
            $late = max(0, (int) $scheduledStart->diffInMinutes($firstIn, false) - $graceIn);
            $late = $late > 0 ? $late + $graceIn : 0;
        }

        if ($shift && ! $shift->isFlexible() && ! $flexi && $lastOut && $awaySession !== 'second_half') {
            $early = max(0, (int) $lastOut->diffInMinutes($scheduledEnd, false) - $graceOut);
            $early = $early > 0 ? $early + $graceOut : 0;
        }

        // Approved short leave and hourly on duty excuse lateness first, then early leaving.
        $excused = $shortLeaveMinutes + $onDutyMinutes;
        if ($excused > 0) {
            $fromLate = min($late, $excused);
            $late -= $fromLate;
            $early = max(0, $early - ($excused - $fromLate));
        }

        if ($leave?->isFullDay()) {
            $status = $leave->isPaid ? 'leave' : 'unpaid_leave';
            $late = $early = 0;
            $exceptions = [];
        } else {
            if ($mark !== null) {
                // The uploaded sheet is the day's evidence: punch exceptions and lateness do not apply.
                $status = $mark->statusOn($holiday !== null, (bool) $resolved['weekly_off'], $leave?->session);
                $late = $early = 0;
                $exceptions = [];
            } else {
                $status = $this->status($resolved, $holiday, $punches, $firstIn, $lastOut, $worked, $fullDay, $halfDay, $regularisations, $exceptions, $onDutyDay !== null && $holiday === null && ! $resolved['weekly_off'] ? 'on_duty' : null);
            }

            if ($leave !== null && $status === 'absent') {
                $status = $leave->isPaid ? 'leave' : 'unpaid_leave';
                $exceptions = [];
            }
        }

        // HP-1b: the attendance policy may make a day a half day for coming in or leaving too far off the shift.
        $halfLate = (int) ($attendancePolicy?->setting('half_day_if_late_beyond') ?? 0);
        $halfEarly = (int) ($attendancePolicy?->setting('half_day_if_early_beyond') ?? 0);
        if ($status === 'present' && $mark === null && (($halfLate > 0 && $late > $halfLate) || ($halfEarly > 0 && $early > $halfEarly))) {
            $status = 'half_day';
            $exceptions[] = 'short_hours';
        }

        // HROne setup parity (TO-5): the shift's absence criteria — a late first check-in makes the first half (or the day)
        // absent; an early last check-out makes the second half absent.
        if ($shift && ! $shift->isFlexible() && $mark === null && $leave === null && $onDutyDay === null && $onDutyHalf === null && in_array($status, ['present', 'half_day'], true) && $regularisations->isEmpty()) {
            $firstHalfAbsent = $firstIn && $shift->absent_first_half_after !== null && $firstIn->gt($scheduledStart->copy()->addMinutes((int) $shift->absent_first_half_after));
            $secondHalfAbsent = $lastOut && $shift->absent_second_half_before !== null && $lastOut->lt($scheduledEnd->copy()->subMinutes((int) $shift->absent_second_half_before));
            if ($firstIn && $shift->absent_full_day_after !== null && $firstIn->gt($scheduledStart->copy()->addMinutes((int) $shift->absent_full_day_after))) {
                [$status, $exceptions] = ['absent', [...$exceptions, 'late_absent']];
            } elseif ($firstHalfAbsent && $secondHalfAbsent) {
                [$status, $exceptions] = ['absent', [...$exceptions, 'first_half_absent', 'second_half_absent']];
            } elseif ($firstHalfAbsent || $secondHalfAbsent) {
                [$status, $exceptions] = ['half_day', [...$exceptions, $firstHalfAbsent ? 'first_half_absent' : 'second_half_absent']];
            }
        }

        // HP-1b sandwich rule: an off day between two absent days is absent (tenant setting, off by default).
        if (in_array($status, ['weekly_off', 'holiday'], true) && $punches->isEmpty() && $mark === null && $this->sandwiched($employee, $date, $status)) {
            $status = 'absent';
            $exceptions = ['sandwich'];
        }

        // HROne setup parity (TO-4): weekly offs and holidays earned by days present in the week; unpaid weekly offs.
        if (in_array($status, ['weekly_off', 'holiday'], true) && $punches->isEmpty() && $mark === null && $this->notEarned($employee, $date, $status, $attendancePolicy)) {
            $exceptions = [$status === 'weekly_off' ? 'weekly_off_not_earned' : 'holiday_not_earned'];
            $status = 'absent';
        }
        if ($status === 'weekly_off' && ($attendancePolicy?->setting('weekly_off_paid') ?? 'paid') === 'unpaid') {
            $exceptions[] = 'unpaid_off';
        }

        if ($override !== null && ! $leave?->isFullDay()) {
            [$status, $worked, $exceptions] = $this->applyOverride($override, $status, $worked, $fullDay, $exceptions, $leave);
            if (! in_array($status, ['present', 'half_day'], true) || $override->status !== null || $override->hasHalves()) {
                $late = $early = 0;
            }
        }

        if ($late > 0 && in_array($status, ['present', 'half_day'], true)) {
            $exceptions[] = 'late';
        }
        if ($early > 0 && in_array($status, ['present', 'half_day'], true)) {
            $exceptions[] = 'early_leave';
        }

        [$overtime, $overtimeStatus] = $mark !== null ? [0, 'none'] : $this->overtime($employee, $date, $shift, $overtimePolicy, $status, $holiday, $resolved, $punches, $worked, $overtimeBaseline, $record, $exceptions);
        $previouslyApproved = $record->exists ? (int) $record->overtime_approved_minutes : 0;

        // An approved overtime claim is the day's approved overtime (and at least the calculated quantity).
        if ($claimedOvertime > 0) {
            $overtime = max($overtime, $claimedOvertime);
            $overtimeStatus = 'approved';
            $previouslyApproved = $claimedOvertime;
            $exceptions = array_values(array_diff($exceptions, ['overtime']));
        }

        $exceptions = array_values(array_unique(array_filter($exceptions)));

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
            ] + ($mark !== null ? ['attendance_mark_id' => $mark->id, 'attendance_mark' => $mark->label()] : [])
              + array_filter([
                  'shift_override_id' => ($resolved['override'] ?? null)?->id,
                  'time_office_request_ids' => $requests->pluck('id')->all() ?: null,
                  'short_leave_minutes' => $shortLeaveMinutes ?: null,
                  'on_duty_minutes' => $onDutyMinutes ?: null,
                  'attendance_override_id' => $override?->id,
                  // HROne parity TX-6: the statuses HR gave each half (only when the override has them).
                  'half_statuses' => $override !== null && $override->hasHalves() && ! $leave?->isFullDay() ? ['first_half' => $override->first_half_status, 'second_half' => $override->second_half_status] : null,
                  'punch_mode' => $punchMode !== 'double' ? $punchMode : null,
                  'auto_shift' => $autoShift?->code,
                  'timing' => $flexi ? 'flexi' : null,
                  // HROne parity TX-12: the holiday change that decided the day (only when there is one).
                  'holiday_change_id' => $holidayChange?->id,
              ]),
            'processed_at' => now(),
        ]);

        return ['status' => $status, 'worked' => $worked, 'overtime' => $overtime, 'exceptions' => $exceptions, 'punches' => $punches];
    }

    /**
     * HROne setup parity (TO-4): under weekly off / holiday entitlement, an off day is earned only with enough days
     * present (on duty, work from home; paid leave when the policy counts it; half days as half) in its week, Monday to
     * Sunday, among the days already processed. Optionally not applied in the joining month.
     */
    private function notEarned(Employee $employee, Carbon $date, string $status, $policy): bool
    {
        $kind = $status === 'weekly_off' ? 'weekly_off' : 'holiday';
        if ($policy === null || ! RequestRules::isYes($policy->setting("{$kind}_entitlement"))) {
            return false;
        }
        $needed = (float) ($policy->setting("{$kind}_min_days") ?? 0);
        if ($needed <= 0) {
            return false;
        }
        if (RequestRules::isYes($policy->setting('entitlement_skip_joining_month')) && $employee->joining_date !== null && Carbon::parse($employee->joining_date)->isSameMonth($date)) {
            return false;
        }
        $counted = ['present', 'on_duty', 'wfh', 'field_duty', ...(RequestRules::isYes($policy->setting('entitlement_counts_leave')) ? ['leave'] : [])];
        $days = AttendanceRecord::query()->where('employee_id', $employee->id)->whereDate('date', '!=', $date->toDateString())
            ->whereDate('date', '>=', $date->copy()->startOfWeek()->toDateString())->whereDate('date', '<=', $date->copy()->endOfWeek()->toDateString())
            ->get(['status', 'is_half_day_leave']);
        $present = $days->sum(fn (AttendanceRecord $d) => match (true) {
            in_array($d->status, $counted, true) => 1.0,
            $d->status === 'half_day' => 0.5,
            default => 0.0,
        });

        return $present < $needed;
    }

    /** The nearest working days before and after are both absent (or unpaid leave), under the sandwich setting. */
    private function sandwiched(Employee $employee, Carbon $date, string $status): bool
    {
        $rule = (string) $this->settings->get('attendance.sandwich_rule', 'none');
        if ($rule === 'none' || ($status === 'holiday' && $rule !== 'weekly_off_and_holiday')) {
            return false;
        }
        $offDays = $rule === 'weekly_off_and_holiday' ? ['weekly_off', 'holiday'] : ['weekly_off'];
        $neighbour = fn (string $operator, string $order) => AttendanceRecord::query()->where('employee_id', $employee->id)
            ->whereDate('date', $operator, $date->toDateString())
            ->whereDate('date', $operator === '<' ? '>=' : '<=', ($operator === '<' ? $date->copy()->subDays(7) : $date->copy()->addDays(7))->toDateString())
            ->whereNotIn('status', $offDays)
            ->orderBy('date', $order)->get(['id', 'status', 'exceptions'])
            ->first(fn (AttendanceRecord $r) => ! in_array('sandwich', $r->exceptions ?? [], true));
        $absent = fn (?AttendanceRecord $r) => $r !== null && in_array($r->status, ['absent', 'unpaid_leave'], true);

        return $absent($neighbour('<', 'desc')) && $absent($neighbour('>', 'asc'));
    }

    /**
     * HP-1: an HR override of the day. A status override wins outright; an override of worked minutes
     * re-derives a working day's status from the shift's thresholds.
     *
     * @param  list<string>  $exceptions
     * @return array{0: string, 1: int, 2: list<string>}
     */
    private function applyOverride(AttendanceOverride $override, string $status, int $worked, int $fullDay, array $exceptions, ?LeaveDay $leave = null): array
    {
        if ($override->worked_minutes !== null) {
            $worked = (int) $override->worked_minutes;
        }

        // HROne parity TX-6: a status per half wins outright, like a whole-day status.
        if ($override->hasHalves()) {
            return [$this->halvesStatus((string) $override->first_half_status, (string) $override->second_half_status, $leave), $worked, []];
        }

        if ($override->status !== null) {
            return [$override->status, $worked, []];
        }

        if ($override->worked_minutes !== null && in_array($status, ['present', 'half_day', 'absent', 'incomplete'], true)) {
            $exceptions = array_values(array_diff($exceptions, ['short_hours', 'missed_punch', 'absent', 'missing_in', 'missing_out', 'invalid_sequence']));
            $status = $worked >= $fullDay ? 'present' : ($worked > 0 ? 'half_day' : 'absent');
            if ($status !== 'present') {
                $exceptions[] = $status === 'absent' ? 'absent' : 'short_hours';
            }
        }

        return [$status, $worked, $exceptions];
    }

    /**
     * HROne parity TX-6: the day's status from the status HR gave each half, read the way the monthly attendance upload
     * reads a split mark (AttendanceMark::combine / statusOn): an absent half beside a counted one is a half day, two
     * counted halves are the half that says the most about the day (present, then work from home, on duty, holiday, weekly
     * off). With approved half-day leave the leave keeps its half and the other half decides: absent there leaves the day
     * as leave, anything else names the day (and the half-day leave flag stays on the record).
     */
    private function halvesStatus(string $first, string $second, ?LeaveDay $leave): string
    {
        if ($leave !== null && ! $leave->isFullDay()) {
            $other = $leave->session === 'first_half' ? $second : $first;

            return $other === 'absent' ? ($leave->isPaid ? 'leave' : 'unpaid_leave') : $other;
        }

        return AttendanceMark::combine($first, $second);
    }

    /**
     * HP-1 arrears: a locked day is never rewritten. When corrections after lock are allowed, the day is worked
     * out again on a scratch record and a change in its paid quantity is kept (or updated, or voided) as a
     * pending attendance arrear for the next payroll.
     */
    private function recordArrear(Employee $employee, Carbon $date, AttendanceRecord $locked): void
    {
        if (! (bool) $this->settings->get('attendance.allow_corrections_after_lock', false)) {
            return;
        }
        // Data import DI-35 ("Override arrear days"): a month whose arrear days HR set stays HR's — its days record none.
        if (AttendanceArrear::query()->where('employee_id', $employee->id)->where('source', 'upload')
            ->whereDate('covers_from', '<=', $date->toDateString())->whereDate('date', '>=', $date->toDateString())->exists()) {
            return;
        }

        $scratch = new AttendanceRecord(['employee_id' => $employee->id, 'date' => $date]);
        ['status' => $status] = $this->calculate($employee, $date, $scratch);

        $before = AttendanceDay::paidQuantity((string) $locked->status, (bool) $locked->is_half_day_leave, $locked->exceptions ?? []);
        $after = AttendanceDay::paidQuantity($status, (bool) $scratch->is_half_day_leave, $scratch->exceptions ?? []);
        // Data import DI-35 ("Override previous month overtime"): overtime approved (or taken back) after the day was locked.
        $overtime = (int) $scratch->overtime_approved_minutes - (int) $locked->overtime_approved_minutes;
        // HROne parity TX-3: an arrear HR held stays held when the day changes again (it is updated, not duplicated).
        // Data import DI-35: only the calculation's own arrears; days HR set for the month by upload stay as set.
        $pending = AttendanceArrear::query()->where('employee_id', $employee->id)->whereDate('date', $date->toDateString())->whereIn('status', ['pending', 'held'])->where('source', 'calculation')->lockForUpdate()->first();

        if (abs($after - $before) < 0.001 && $overtime === 0) {
            $pending?->withAuditReason('The day is back to what payroll paid')->update(['status' => 'void']);

            return;
        }

        $values = ['status_before' => (string) $locked->status, 'status_after' => $status, 'paid_before' => $before, 'paid_after' => $after, 'delta_days' => round($after - $before, 2), 'note' => 'Corrected after the payroll lock'];
        if (! $locked->is_locked) {
            $values['note'] = 'Corrected after the attendance month was closed'; // HROne parity TX-3: a closed, not locked, day
        }
        $values['overtime_minutes'] = $overtime;
        $arrear = $pending ?? new AttendanceArrear(['employee_id' => $employee->id, 'date' => $date]);
        $arrear->fill($values)->withAuditReason('Attendance '.lcfirst($values['note']))->save();

        AttendanceEvent::dispatch('attendance.arrear_recorded', $employee, $arrear, ['date' => $date->toDateString(), 'from' => $locked->status, 'to' => $status, 'delta_days' => $values['delta_days']]
            + ($overtime !== 0 ? ['overtime_minutes' => $overtime] : []));
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

        // HROne setup parity (TO-5): a shift may say how early before its start a check-in counts.
        $start = $shift->early_checkin_minutes !== null ? $shift->startsAt($date, $timezone)->subMinutes((int) $shift->early_checkin_minutes) : $shift->startsAt($date, $timezone)->subHours($hours);

        return [$start, $shift->endsAt($date, $timezone)->addHours($hours)];
    }

    /**
     * HROne setup parity (TO-5): when the employee's work schedule lists shifts for automatic correction, the day takes
     * the listed shift whose start is nearest the day's first punch (on weekly offs and holidays only when the schedule
     * says so). Null keeps the scheduled shift.
     * HROne parity TX-5: a rostered day takes part only when its roster allows automatic shift correction
     * (ShiftResolver's 'auto_shift'); the rostered shift is then one of the shifts to choose from (and wins a tie).
     *
     * @param  array<string, mixed>  $resolved
     */
    private function autoShift(Employee $employee, Carbon $date, array $resolved): ?Shift
    {
        $schedule = $resolved['schedule'] ?? null;
        $ids = array_values(array_filter((array) ($schedule?->auto_shift_ids ?? [])));
        $rostered = isset($resolved['override']);
        if ($ids === [] || ($rostered && empty($resolved['auto_shift'])) || ($resolved['weekly_off'] && ! $schedule->auto_shift_on_off_days)) {
            return null;
        }
        $rosteredShiftId = $rostered ? $resolved['shift']?->id : null;
        if ($rosteredShiftId !== null) {
            $ids[] = (int) $rosteredShiftId;
        }
        $candidates = Shift::query()->whereIn('id', $ids)->get()->filter(fn (Shift $s) => ! $s->isFlexible());
        if ($rosteredShiftId !== null) {
            $candidates = $candidates->sortBy(fn (Shift $s) => (int) $s->id === (int) $rosteredShiftId ? 0 : 1)->values();
        }
        $timezone = $this->timezones->for($employee, $resolved['shift'] ?? $candidates->first(), $date);
        $dayStart = Carbon::parse($date->toDateString().' 00:00:00', $timezone)->setTimezone(config('app.timezone'));
        $first = $this->punches->punchesBetween($employee, $dayStart->copy()->subHours(4), $dayStart->copy()->addDay())->first();
        if ($first === null || $candidates->isEmpty()) {
            return null;
        }

        return $candidates->sortBy(fn (Shift $s) => abs($s->startsAt($date, $timezone)->diffInMinutes($first->punched_at, false)))->first();
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

    private function status(array $resolved, $holiday, Collection $punches, ?Carbon $firstIn, ?Carbon $lastOut, int $worked, int $fullDay, int $halfDay, Collection $regularisations, array &$exceptions, ?string $requestedSpecial = null): string
    {
        $special = $regularisations->first(fn ($r) => in_array($r->type, self::SPECIAL_TYPES, true))?->type ?? $requestedSpecial;

        if ($special && $firstIn === null) {
            return $special;
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
            return $special;
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
