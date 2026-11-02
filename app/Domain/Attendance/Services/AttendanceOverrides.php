<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Events\AttendanceEvent;
use App\Domain\Attendance\Models\AttendanceOverride;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * HR's word on a day (HROne parity HP-1 "change attendance status" and "override working hours"). The
 * override is an input of the engine, audited with its reason; the day is recalculated at once.
 *
 * HROne parity TX-6 ("Change attendance status" — From, To, First half, Second half): set() also takes a status per half
 * (both halves or neither; two equal halves are stored as that whole-day status), and setRange() changes a date range for
 * several employees through set() day by day, answering per employee — the days changed, and each refused day or employee
 * with the reason (outside the actor's access scope, one's own attendance, not employed that day, a locked day, a day of a
 * closed attendance month) — instead of stopping at the first refusal.
 */
final class AttendanceOverrides
{
    /** HROne parity TX-6: the longest range setRange() changes at once (days). */
    public const MAX_RANGE_DAYS = 31;

    public function __construct(
        private readonly AttendanceProcessor $processor,
        private readonly SettingsRepository $settings,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  ?string  $firstHalf  HROne parity TX-6: the first half's status (with $secondHalf; null = the whole-day status, as before)
     * @param  ?string  $secondHalf  HROne parity TX-6: the second half's status
     */
    public function set(Employee $employee, Carbon|string $date, ?string $status, ?int $workedMinutes, string $reason, ?User $actor = null, ?string $firstHalf = null, ?string $secondHalf = null): AttendanceRecord
    {
        $date = Carbon::parse($date)->startOfDay();
        [$status, $firstHalf, $secondHalf] = self::statuses($status, $firstHalf, $secondHalf);
        if ($status === null && $firstHalf === null && $workedMinutes === null) {
            throw new RuntimeException('Set a status, worked minutes, or both.');
        }
        if ($status !== null && ! in_array($status, AttendanceOverride::STATUSES, true)) {
            throw new RuntimeException("Unknown attendance status [{$status}].");
        }
        if ($workedMinutes !== null && ($workedMinutes < 0 || $workedMinutes > 1440)) {
            throw new RuntimeException('Worked minutes must be between 0 and 1440.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give the reason for the override.');
        }
        if ($date->gt(now()->startOfDay())) {
            throw new RuntimeException('A day can be overridden once it has started.');
        }
        $this->assertUnlocked($employee, $date);
        $actor ??= auth()->user();

        DB::transaction(function () use ($employee, $date, $status, $workedMinutes, $reason, $actor, $firstHalf, $secondHalf) {
            $override = AttendanceOverride::query()->where('employee_id', $employee->id)->whereDate('date', $date->toDateString())->lockForUpdate()->first()
                ?? new AttendanceOverride(['employee_id' => $employee->id, 'date' => $date->copy()]);
            $attributes = ['status' => $status, 'worked_minutes' => $workedMinutes, 'reason' => $reason, 'created_by' => $actor?->id];
            // TX-6: the halves are written when given, and cleared when a day set by half is set again without them.
            $stored = $override->getAttributes();
            if ($firstHalf !== null || ($stored['first_half_status'] ?? null) !== null || ($stored['second_half_status'] ?? null) !== null) {
                $attributes += ['first_half_status' => $firstHalf, 'second_half_status' => $secondHalf];
            }
            $override->fill($attributes)->withAuditReason($reason)->save();
            AttendanceEvent::dispatch('attendance.override_set', $employee, $override, ['date' => $date->toDateString(), 'status' => $status, 'worked_minutes' => $workedMinutes, 'reason' => $reason]
                + ($firstHalf !== null ? ['first_half_status' => $firstHalf, 'second_half_status' => $secondHalf] : []));
        });

        return $this->processor->process($employee, $date);
    }

    public function clear(AttendanceOverride $override, string $reason): AttendanceRecord
    {
        $override->loadMissing('employee');
        $this->assertUnlocked($override->employee, $override->date);
        $employee = $override->employee;
        $date = $override->date->copy();
        $override->withAuditReason($reason)->delete();

        return $this->processor->process($employee, $date);
    }

    /**
     * HROne parity TX-6: the whole-day status or the two halves, checked. Both halves or neither; a half takes one of
     * AttendanceOverride::HALF_STATUSES; never a whole-day status and halves together; two equal halves are that
     * whole-day status (so a day set by half always has two different halves).
     *
     * @return array{0: ?string, 1: ?string, 2: ?string} [status, first half, second half]
     */
    public static function statuses(?string $status, ?string $firstHalf, ?string $secondHalf): array
    {
        $firstHalf = filled($firstHalf) ? (string) $firstHalf : null;
        $secondHalf = filled($secondHalf) ? (string) $secondHalf : null;
        if ($firstHalf === null && $secondHalf === null) {
            return [$status, null, null];
        }
        if ($firstHalf === null || $secondHalf === null) {
            throw new RuntimeException('Give a status for both halves of the day.');
        }
        if ($status !== null) {
            throw new RuntimeException('Choose a status for the whole day or for each half, not both.');
        }
        foreach ([$firstHalf, $secondHalf] as $half) {
            if (! in_array($half, AttendanceOverride::HALF_STATUSES, true)) {
                throw new RuntimeException("Unknown attendance status [{$half}] for a half of the day.");
            }
        }

        return $firstHalf === $secondHalf ? [$firstHalf, null, null] : [null, $firstHalf, $secondHalf];
    }

    /**
     * HROne parity TX-6 ("Change attendance status" for chosen employees, matrix 24 §24.8 #9, §24.5.1 #4): every day from
     * $from to $to of each employee gets the whole-day status, or the two halves, (and / or worked minutes) through set(),
     * one day at a time with the existing audit and recalculation. Each employee is handled on their own: refused when
     * outside the actor's access scope or when it is the actor's own attendance; days the employee was not employed on
     * (before joining, after leaving), locked days and days of a closed attendance month (unless corrections after lock are
     * allowed — then the locked day keeps its values and the difference becomes an arrear) are refused and listed, the
     * other days are changed. At most MAX_RANGE_DAYS days, none after today. One audited operation (or the caller's, inside
     * a bulk run).
     *
     * @param  iterable<Employee>  $employees
     * @return array{days: int, done: list<array{employee_id: int, label: string, message: string}>, refused: list<array{employee_id: int, label: string, reason: string, dates: ?string}>}
     */
    public function setRange(iterable $employees, Carbon|string $from, Carbon|string $to, ?string $status, ?string $firstHalf, ?string $secondHalf, string $reason, ?User $actor = null, ?int $workedMinutes = null): array
    {
        [$from, $to] = self::range($from, $to);
        [$status, $firstHalf, $secondHalf] = self::statuses($status, $firstHalf, $secondHalf);
        if ($status === null && $firstHalf === null && $workedMinutes === null) {
            throw new RuntimeException('Set a status, worked minutes, or both.');
        }
        if ($status !== null && ! in_array($status, AttendanceOverride::STATUSES, true)) {
            throw new RuntimeException("Unknown attendance status [{$status}].");
        }
        if ($workedMinutes !== null && ($workedMinutes < 0 || $workedMinutes > 1440)) {
            throw new RuntimeException('Worked minutes must be between 0 and 1440.');
        }
        $reason = mb_substr(trim($reason), 0, 500);
        if ($reason === '') {
            throw new RuntimeException('Give the reason for the change.');
        }
        $actor ??= auth()->user();
        if ($actor !== null && ! $actor->hasPermission('attendance.override')) {
            throw new RuntimeException('You may not change attendance status.');
        }
        $what = self::describe($status, $firstHalf, $secondHalf, $workedMinutes);

        $result = ['days' => 0, 'done' => [], 'refused' => []];
        $run = function () use ($employees, $from, $to, $status, $firstHalf, $secondHalf, $workedMinutes, $reason, $actor, $what, &$result) {
            foreach ($employees as $employee) {
                $employee->loadMissing('person');
                $row = ['employee_id' => (int) $employee->id, 'label' => $employee->auditLabel()];
                $whole = match (true) {
                    $actor !== null && ! app(AccessScopes::class)->allows($actor, $employee) => 'Outside your access scope.',
                    $actor !== null && $employee->user_id !== null && (int) $employee->user_id === (int) $actor->id => 'You cannot change your own attendance.',
                    ! in_array($employee->lifecycle_state?->value, AttendanceMonthPopulation::states(), true) => 'Not employed: the employee is '.str_replace('_', ' ', (string) $employee->lifecycle_state?->value).'.',
                    default => null,
                };
                if ($whole !== null) {
                    $result['refused'][] = $row + ['reason' => $whole, 'dates' => null];

                    continue;
                }

                [$days, $refusals] = $this->plan($employee, $from, $to);
                $changed = 0;
                $leaveKept = 0;
                foreach ($days as $date) {
                    try {
                        $record = $this->set($employee, $date, $status, $workedMinutes, $reason, $actor, $firstHalf, $secondHalf);
                    } catch (RuntimeException $e) {
                        $refusals[$date] = $e->getMessage();

                        continue;
                    }
                    $changed++;
                    if ($record->leave_request_id !== null && ! $record->is_half_day_leave && in_array($record->status, ['leave', 'unpaid_leave'], true)) {
                        $leaveKept++;
                    }
                }
                ksort($refusals);
                foreach (self::spans($refusals) as [$dates, $why]) {
                    $result['refused'][] = $row + ['reason' => $why, 'dates' => $dates];
                }
                if ($changed > 0) {
                    $result['days'] += $changed;
                    $result['done'][] = $row + ['message' => $changed.' day(s) set to '.$what
                        .($leaveKept > 0 ? "; {$leaveKept} day(s) of approved full-day leave keep the leave" : '')
                        .($refusals !== [] ? '; '.count($refusals).' day(s) refused' : '').'.'];
                }
            }

            return ['succeeded' => count($result['done']), 'failed' => count($result['refused'])];
        };

        // Inside a bulk run the run's own operation already groups the audit trail; otherwise this is one operation.
        Context::get('audit.operation_id') !== null
            ? $run()
            : $this->audit->operation('attendance', 'Change attendance status '.$from->toDateString().' – '.$to->toDateString(), $run, entityType: AttendanceOverride::class);

        return $result;
    }

    /** "Present", "first half Present, second half Absent", "worked 8:00" — what a change sets, for people. */
    public static function describe(?string $status, ?string $firstHalf, ?string $secondHalf, ?int $workedMinutes = null): string
    {
        return implode(', ', array_filter([
            $status !== null ? AttendanceOverride::halfLabel($status) : null,
            $firstHalf !== null ? 'first half '.AttendanceOverride::halfLabel($firstHalf).', second half '.AttendanceOverride::halfLabel((string) $secondHalf) : null,
            $workedMinutes !== null ? sprintf('worked %d:%02d', intdiv($workedMinutes, 60), $workedMinutes % 60) : null,
        ]));
    }

    /**
     * The range, checked: forward, at most MAX_RANGE_DAYS days, nothing after today.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function range(Carbon|string $from, Carbon|string $to): array
    {
        try {
            $from = Carbon::parse($from)->startOfDay();
            $to = Carbon::parse($to)->startOfDay();
        } catch (Throwable) {
            throw new RuntimeException('Choose the first and the last day.');
        }
        if ($to->lt($from)) {
            throw new RuntimeException('The last day must not be before the first day.');
        }
        if ($to->gt(now()->startOfDay())) {
            throw new RuntimeException('A day can be changed once it has started: choose days up to today.');
        }
        if ($from->diffInDays($to) + 1 > self::MAX_RANGE_DAYS) {
            throw new RuntimeException('Change at most '.self::MAX_RANGE_DAYS.' days at a time.');
        }

        return [$from, $to];
    }

    /**
     * The days of the range to change, and the days refused before trying (not employed, locked, closed month).
     *
     * @return array{0: list<string>, 1: array<string, string>} [days to change, Y-m-d => reason]
     */
    private function plan(Employee $employee, Carbon $from, Carbon $to): array
    {
        $corrections = (bool) $this->settings->get('attendance.allow_corrections_after_lock', false);
        $locked = $corrections ? [] : array_flip(AttendanceRecord::query()->where('employee_id', $employee->id)->where('is_locked', true)
            ->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())->all());
        $closed = $corrections ? [] : array_flip(app(AttendanceMonthCloses::class)->closedDates($employee, $from, $to));

        $days = [];
        $refused = [];
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $date = $day->toDateString();
            $why = match (true) {
                $employee->joining_date !== null && $employee->joining_date->copy()->startOfDay()->gt($day) => 'Not employed on this day (joins '.$employee->joining_date->format('d M Y').').',
                $employee->exit_date !== null && $employee->exit_date->copy()->startOfDay()->lt($day) => 'Not employed on this day (left '.$employee->exit_date->format('d M Y').').',
                isset($locked[$date]) => 'Locked (finalized days).',
                isset($closed[$date]) => 'In a closed attendance month.',
                default => null,
            };
            if ($why === null) {
                $days[] = $date;
            } else {
                $refused[$date] = $why;
            }
        }

        return [$days, $refused];
    }

    /**
     * Consecutive days refused for the same reason, as one line each: [["12 Oct 2026" | "12 Oct – 14 Oct 2026", reason], …].
     *
     * @param  array<string, string>  $refusals  Y-m-d => reason, in date order
     * @return list<array{0: string, 1: string}>
     */
    private static function spans(array $refusals): array
    {
        $spans = [];
        foreach ($refusals as $date => $why) {
            $last = array_key_last($spans);
            if ($last !== null && $spans[$last][2] === $why && Carbon::parse($spans[$last][1])->addDay()->toDateString() === $date) {
                $spans[$last][1] = $date;

                continue;
            }
            $spans[] = [$date, $date, $why];
        }

        return array_map(function (array $s) {
            [$first, $last] = [Carbon::parse($s[0]), Carbon::parse($s[1])];

            return [$first->eq($last) ? $first->format('d M Y') : $first->format($first->isSameYear($last) ? 'd M' : 'd M Y').' – '.$last->format('d M Y'), $s[2]];
        }, $spans);
    }

    private function assertUnlocked(Employee $employee, Carbon $date): void
    {
        // HROne parity TX-3: a day of a closed attendance month is refused like a locked day.
        app(AttendanceMonthCloses::class)->assertOpen($employee, $date, $date, 'This day is in a closed attendance month.');
        if ((bool) $this->settings->get('attendance.allow_corrections_after_lock', false)) {
            return;
        }
        if (AttendanceRecord::query()->where('employee_id', $employee->id)->whereDate('date', $date->toDateString())->where('is_locked', true)->exists()) {
            throw new RuntimeException('This day is locked (finalized days).');
        }
    }
}
