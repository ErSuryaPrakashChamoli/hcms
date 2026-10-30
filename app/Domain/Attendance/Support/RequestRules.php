<?php

namespace App\Domain\Attendance\Support;

use App\Domain\Attendance\Models\AttendancePunch;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\TimeOfficeRequest;
use App\Domain\Attendance\Services\HolidayResolver;
use App\Domain\Attendance\Services\ShiftResolver;
use App\Domain\Configuration\Models\PolicyVersion;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * HROne setup parity (TO-3): the request rules HROne's check-in policy sets for regularisation (AR), on duty (OD),
 * shift / week-off change and short leave, read from the policy that owns each request type in PeopleOS (attendance
 * for AR, time-office for OD and shift / week-off change, short-leave). Every rule is checked only when the policy
 * sets it, so policies written before keep their behaviour. Keys use a prefix per request type: `ar`, `on_duty`,
 * `shift_change`, `week_off_change`, `short_leave`.
 */
final class RequestRules
{
    public function __construct(private readonly ShiftResolver $shifts, private readonly HolidayResolver $holidays) {}

    /** Who raises the request: the employee, their manager, or HR (attendance.manage). */
    public static function role(Employee $employee, ?User $requester): string
    {
        if ($requester === null || (int) $employee->user_id === (int) $requester->id) {
            return 'employee';
        }

        return $requester->hasPermission('attendance.manage') ? 'hr' : 'manager';
    }

    /**
     * How far back the requester may go: `{prefix}_manager_backdated_days` / `{prefix}_hr_backdated_days` for managers
     * and HR when set, else the employee's window. Null keeps the caller's own rule.
     */
    public function backdatedDays(?PolicyVersion $policy, string $prefix, string $role, ?int $employeeDays): ?int
    {
        $own = $policy?->setting("{$prefix}_{$role}_backdated_days");

        return $role !== 'employee' && is_numeric($own) ? (int) $own : $employeeDays;
    }

    /** `{prefix}_future_allowed` = No refuses dates after today. */
    public function assertFuture(?PolicyVersion $policy, string $prefix, Carbon $from, string $label): void
    {
        if (self::isNo($policy?->setting("{$prefix}_future_allowed")) && $from->gt(now()->startOfDay())) {
            throw new RuntimeException("{$label} cannot be requested for a future date.");
        }
    }

    /** `{prefix}_not_on` (weekly_off, holiday): no request on the employee's weekly offs or holidays. */
    public function assertDayTypes(?PolicyVersion $policy, string $prefix, Employee $employee, Carbon $from, Carbon $to, string $label): void
    {
        $blocked = array_values((array) ($policy?->setting("{$prefix}_not_on") ?? []));
        if ($blocked === []) {
            return;
        }
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            if (in_array('holiday', $blocked, true) && $this->holidays->holidayOn($employee, $day->copy()) !== null) {
                throw new RuntimeException("{$label} cannot be requested on a holiday ({$day->format('d M Y')}).");
            }
            if (in_array('weekly_off', $blocked, true) && $this->shifts->resolve($employee, $day->copy())['weekly_off']) {
                throw new RuntimeException("{$label} cannot be requested on a weekly off ({$day->format('d M Y')}).");
            }
        }
    }

    /** `{prefix}_after_process` = No: no request for a day already processed for payroll (locked). */
    public function assertNotProcessed(?PolicyVersion $policy, string $prefix, Employee $employee, Carbon $from, Carbon $to, string $label): void
    {
        if (! self::isNo($policy?->setting("{$prefix}_after_process"))) {
            return;
        }
        $locked = AttendanceRecord::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->where('is_locked', true)
            ->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())->exists();
        if ($locked) {
            throw new RuntimeException("{$label} cannot be requested once attendance is processed for payroll.");
        }
    }

    /** `{prefix}_attachment_required` = Yes: at least one file. @param  list<string>  $attachments */
    public function assertAttachment(?PolicyVersion $policy, string $prefix, array $attachments, string $label): void
    {
        if (self::isYes($policy?->setting("{$prefix}_attachment_required")) && array_filter($attachments) === []) {
            throw new RuntimeException("Attach a document to the {$label} request.");
        }
    }

    /**
     * `{prefix}_limit_period` (week / month / year) with `{prefix}_limit`: the most requests in the period, counted by
     * the caller's query for the period that contains the date.
     *
     * @param  callable(Carbon, Carbon): int  $count
     */
    public function assertPeriodLimit(?PolicyVersion $policy, string $prefix, Carbon $date, callable $count, string $label): void
    {
        $period = (string) ($policy?->setting("{$prefix}_limit_period") ?? 'none');
        $limit = (int) ($policy?->setting("{$prefix}_limit") ?? 0);
        if ($limit <= 0 || ! in_array($period, ['week', 'month', 'year'], true)) {
            return;
        }
        [$start, $end] = match ($period) {
            'week' => [$date->copy()->startOfWeek(), $date->copy()->endOfWeek()],
            'month' => [$date->copy()->startOfMonth(), $date->copy()->endOfMonth()],
            'year' => [$date->copy()->startOfYear(), $date->copy()->endOfYear()],
        };
        if ($count($start, $end) >= $limit) {
            throw new RuntimeException("You can raise at most {$limit} {$label} request(s) a {$period}.");
        }
    }

    /** AR `ar_requires_punch` = Yes: the day needs at least one recorded punch. */
    public function assertHasPunch(?PolicyVersion $policy, Employee $employee, Carbon $date): void
    {
        if (! self::isYes($policy?->setting('ar_requires_punch'))) {
            return;
        }
        $punched = AttendancePunch::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->where('processing_status', '!=', 'rejected')
            ->whereBetween('punched_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])->exists();
        if (! $punched) {
            throw new RuntimeException('A regularisation needs at least one punch on the day.');
        }
    }

    /** AR `ar_within_shift` = Yes: the times asked for fall within the day's shift (start to end). */
    public function assertWithinShift(?PolicyVersion $policy, Employee $employee, Carbon $date, ?Carbon $in, ?Carbon $out): void
    {
        if (! self::isYes($policy?->setting('ar_within_shift')) || ($in === null && $out === null)) {
            return;
        }
        $shift = $this->shifts->resolve($employee, $date->copy())['shift'] ?? null;
        if ($shift === null) {
            return; // a weekly off or no schedule: nothing to compare with
        }
        $start = Carbon::parse($date->toDateString().' '.$shift->start_time);
        $end = Carbon::parse($date->toDateString().' '.$shift->end_time);
        if ($end->lte($start)) {
            $end->addDay(); // night shift
        }
        foreach (array_filter([$in, $out]) as $time) {
            if ($time->lt($start) || $time->gt($end)) {
                throw new RuntimeException("Regularised times must fall within the shift ({$start->format('H:i')}–{$end->format('H:i')}).");
            }
        }
    }

    /**
     * Organisation setting `attendance.requests_after_benefits` = off (HROne: "Can employee apply AR, OD and SL after
     * overtime and comp off are generated?"): no regularisation, on duty or short leave for a day that already earned
     * approved overtime or a comp-off.
     */
    public function assertNoBenefitsYet(Employee $employee, Carbon $from, Carbon $to, string $label): void
    {
        if ((bool) app(SettingsRepository::class)->get('attendance.requests_after_benefits', true)) {
            return;
        }
        $earned = TimeOfficeRequest::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->whereIn('type', ['overtime', 'comp_off'])
            ->whereIn('status', TimeOfficeRequest::EFFECTIVE)->whereDate('from_date', '<=', $to->toDateString())->whereDate('to_date', '>=', $from->toDateString())->exists()
            || AttendanceRecord::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->where('overtime_status', 'approved')
                ->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())->exists();
        if ($earned) {
            throw new RuntimeException("{$label} cannot be requested for a day that already earned overtime or a comp-off.");
        }
    }

    /**
     * HROne setup parity (TO-4): the attendance policy's request lock. Between its days of the month (attendance and
     * payroll processing), requests and decisions for days before this month are refused.
     */
    public function assertNotLocked(Employee $employee, Carbon $date, string $label): void
    {
        $policy = app(PolicyResolver::class)->resolve('attendance', $employee, now());
        if (! self::isYes($policy?->setting('request_lock'))) {
            return;
        }
        $from = (int) ($policy->setting('request_lock_from_day') ?? 0);
        $to = (int) ($policy->setting('request_lock_to_day') ?? 0);
        $today = (int) now()->day;
        $inLock = $from > 0 && $to > 0 && ($from <= $to ? ($today >= $from && $today <= $to) : ($today >= $from || $today <= $to));
        if ($inLock && $date->lt(now()->startOfMonth())) {
            throw new RuntimeException("{$label} for last month is locked from day {$from} to day {$to} while attendance and payroll are processed.");
        }
    }

    /**
     * HROne setup parity (TO-5): a shift may not allow short leave, regularisation, on duty or leave; days on such a
     * shift refuse that request.
     *
     * @param  'short_leave'|'regularisation'|'on_duty'|'leave'  $kind
     */
    public function assertShiftAllows(Employee $employee, Carbon $from, Carbon $to, string $kind, string $label): void
    {
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $shift = $this->shifts->resolve($employee, $day->copy())['shift'] ?? null;
            if ($shift !== null && $shift->getAttribute("allow_{$kind}") === false) {
                throw new RuntimeException("{$label} is not allowed on the {$shift->name} shift ({$day->format('d M Y')}).");
            }
        }
    }

    public static function isYes(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'yes'], true);
    }

    public static function isNo(mixed $value): bool
    {
        return in_array($value, [false, 0, '0', 'false', 'no'], true);
    }
}
