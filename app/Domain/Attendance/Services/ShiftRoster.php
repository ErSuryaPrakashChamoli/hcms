<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Models\ShiftOverride;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The day-level roster (HROne parity HP-1): HR puts employees on a shift (or a weekly off) for given dates,
 * over whatever their schedule says. One bulk action is one audited operation; past days are recalculated.
 *
 * HROne parity TX-5 ("Assign shift", the roster board): assignPattern() / removePattern() take a range with weekly-off
 * weekdays (those days become weekly offs, the rest take the shift) and an optional automatic shift correction for the
 * rostered days, and answer per employee — done, or refused with the reason (outside the actor's access scope, not
 * employed on any day of the range, a locked day, a day of a closed attendance month) — instead of stopping at the
 * first refusal. Days outside the employee's employment are left as they are.
 */
final class ShiftRoster
{
    /** Weekday keys (as work schedule patterns name them) => labels. */
    public const WEEKDAYS = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];

    public function __construct(
        private readonly AttendanceProcessor $processor,
        private readonly SettingsRepository $settings,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  iterable<Employee>  $employees
     * @param  list<string>  $weeklyOffDays  HROne parity TX-5: weekdays of the range that become weekly offs (none = every day takes $shift)
     * @param  ?bool  $autoShift  HROne parity TX-5: automatic shift correction on the rostered days (null = not chosen, as before)
     * @return int overrides written
     */
    public function assign(iterable $employees, Carbon|string $from, Carbon|string $to, ?Shift $shift, string $reason, ?User $actor = null, string $source = 'roster', array $weeklyOffDays = [], ?bool $autoShift = null): int
    {
        [$from, $to] = $this->range($from, $to);
        $weeklyOffDays = self::weekdays($weeklyOffDays);
        $actor ??= auth()->user();
        $written = 0;

        $this->audit->operation('attendance', ($shift ? "Roster {$shift->name}" : 'Roster weekly off').' '.$from->toDateString().' – '.$to->toDateString(), function () use ($employees, $from, $to, $shift, $reason, $actor, $source, $weeklyOffDays, $autoShift, &$written) {
            foreach ($employees as $employee) {
                DB::transaction(function () use ($employee, $from, $to, $shift, $reason, $actor, $source, $weeklyOffDays, $autoShift, &$written) {
                    $this->assertUnlocked($employee, $from, $to);
                    $written += $this->write($employee, $this->pattern($from, $to, $shift, $weeklyOffDays), $autoShift, $reason, $actor, $source);
                });
                $this->reprocess($employee, $from, $to);
            }

            return ['succeeded' => $written];
        }, entityType: ShiftOverride::class);

        return $written;
    }

    /** @param  iterable<Employee>  $employees */
    public function clear(iterable $employees, Carbon|string $from, Carbon|string $to, string $reason): int
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay();
        $removed = 0;

        foreach ($employees as $employee) {
            $this->assertUnlocked($employee, $from, $to);
            ShiftOverride::query()->where('employee_id', $employee->id)->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())
                ->get()->each(function (ShiftOverride $override) use ($reason, &$removed) {
                    $override->withAuditReason($reason)->delete();
                    $removed++;
                });
            $this->reprocess($employee, $from, $to);
        }

        return $removed;
    }

    /**
     * HROne parity TX-5 ("Assign shift" for a date range, matrix 24 §24.8 #8): each employee's days from $from to $to take
     * $shift (null = weekly off), except the $weeklyOffDays weekdays, which become weekly offs; $autoShift lets the day's
     * first check-in correct the rostered shift (with the shifts the work schedule lists for correction). Each employee is
     * handled on their own: refused when outside the actor's access scope, when not employed on any day of the range, or
     * when a day is locked or in a closed attendance month (unless corrections after lock are allowed — then the locked
     * day keeps its values and the difference becomes an arrear, as for every roster change). Past and present days are
     * recalculated. One audited operation (or the caller's, inside a bulk run).
     *
     * @param  iterable<Employee>  $employees
     * @param  list<string>  $weeklyOffDays
     * @return array{days: int, done: list<array{employee_id: int, label: string, message: string}>, refused: list<array{employee_id: int, label: string, reason: string}>}
     */
    public function assignPattern(iterable $employees, Carbon|string $from, Carbon|string $to, ?Shift $shift, array $weeklyOffDays = [], bool $autoShift = false, string $reason = '', ?User $actor = null, string $source = 'roster'): array
    {
        [$from, $to] = $this->range($from, $to);
        $weeklyOffDays = self::weekdays($weeklyOffDays);
        $reason = $this->reason($reason);
        $actor ??= auth()->user();
        $label = ($shift ? "Assign {$shift->name}" : 'Assign weekly off').' '.$from->toDateString().' – '.$to->toDateString();

        return $this->perEmployee($label, $employees, $from, $to, $actor, function (Employee $employee, Carbon $first, Carbon $last) use ($shift, $weeklyOffDays, $autoShift, $reason, $actor, $source) {
            $days = $this->pattern($first, $last, $shift, $weeklyOffDays);
            $written = $this->write($employee, $days, $autoShift, $reason, $actor, $source);
            $off = count(array_filter($days, fn ($id) => $id === null));
            $parts = array_filter([$written - $off > 0 ? ($written - $off).' on '.$shift?->name : null, $off > 0 ? $off.' weekly off' : null]);

            return [$written, $written.' day(s) rostered: '.implode(', ', $parts).($autoShift && $written > $off ? '; automatic shift correction allowed' : '')];
        });
    }

    /**
     * HROne parity TX-5 ("Remove"): clears the rostered days from $from to $to, so those days follow the schedule again —
     * per employee, with the same refusals as assignPattern(); past and present days are recalculated.
     *
     * @param  iterable<Employee>  $employees
     * @return array{days: int, done: list<array{employee_id: int, label: string, message: string}>, refused: list<array{employee_id: int, label: string, reason: string}>}
     */
    public function removePattern(iterable $employees, Carbon|string $from, Carbon|string $to, string $reason, ?User $actor = null): array
    {
        [$from, $to] = $this->range($from, $to);
        $reason = $this->reason($reason);
        $actor ??= auth()->user();

        return $this->perEmployee('Remove rostered days '.$from->toDateString().' – '.$to->toDateString(), $employees, $from, $to, $actor, function (Employee $employee, Carbon $first, Carbon $last) use ($reason) {
            $removed = 0;
            ShiftOverride::query()->where('employee_id', $employee->id)->whereDate('date', '>=', $first->toDateString())->whereDate('date', '<=', $last->toDateString())
                ->lockForUpdate()->get()->each(function (ShiftOverride $override) use ($reason, &$removed) {
                    $override->withAuditReason($reason)->delete();
                    $removed++;
                });

            return [$removed, $removed > 0 ? $removed.' rostered day(s) removed: back to the schedule' : 'Nothing was rostered on these days'];
        });
    }

    /**
     * Normalise weekday names (mon … sun, Monday … Sunday, any case) to the pattern keys; refuses anything else.
     *
     * @param  iterable<string>  $days
     * @return list<string>
     */
    public static function weekdays(iterable $days): array
    {
        $keys = [];
        foreach ($days as $day) {
            $day = mb_strtolower(trim((string) $day));
            if ($day === '') {
                continue;
            }
            $key = mb_substr($day, 0, 3);
            if (! array_key_exists($key, self::WEEKDAYS) || ! in_array($day, [$key, mb_strtolower(self::WEEKDAYS[$key])], true)) {
                throw new RuntimeException("'{$day}' is not a weekday (use Mon … Sun).");
            }
            $keys[$key] = $key;
        }

        return array_values(array_intersect(array_keys(self::WEEKDAYS), $keys));
    }

    /**
     * The roster each employee's days get, one employee at a time, as the TX-5 result summary.
     *
     * @param  iterable<Employee>  $employees
     * @param  callable(Employee, Carbon, Carbon): array{0: int, 1: string}  $act  writes the employee's days (inside a transaction, after the lock rule)
     * @return array{days: int, done: list<array{employee_id: int, label: string, message: string}>, refused: list<array{employee_id: int, label: string, reason: string}>}
     */
    private function perEmployee(string $label, iterable $employees, Carbon $from, Carbon $to, ?User $actor, callable $act): array
    {
        $result = ['days' => 0, 'done' => [], 'refused' => []];
        $run = function () use ($employees, $from, $to, $actor, $act, &$result) {
            foreach ($employees as $employee) {
                $employee->loadMissing('person');
                $row = ['employee_id' => (int) $employee->id, 'label' => $employee->auditLabel()];
                if ($actor !== null && ! app(AccessScopes::class)->allows($actor, $employee)) {
                    $result['refused'][] = $row + ['reason' => 'Outside your access scope.'];

                    continue;
                }
                $span = $this->activeSpan($employee, $from, $to);
                if (is_string($span)) {
                    $result['refused'][] = $row + ['reason' => $span];

                    continue;
                }
                [$first, $last] = $span;
                try {
                    [$count, $message] = DB::transaction(function () use ($employee, $first, $last, $act) {
                        $this->assertUnlocked($employee, $first, $last);

                        return $act($employee, $first, $last);
                    });
                } catch (RuntimeException $e) {
                    $result['refused'][] = $row + ['reason' => $e->getMessage()];

                    continue;
                }
                if ($count > 0) {
                    $this->reprocess($employee, $first, $last);
                }
                $outside = (int) round($from->diffInDays($to) - $first->diffInDays($last));
                $result['days'] += $count;
                $result['done'][] = $row + ['message' => $message.($outside > 0 ? "; {$outside} day(s) outside the employment left as they were" : '').'.'];
            }

            return ['succeeded' => count($result['done']), 'failed' => count($result['refused'])];
        };

        // Inside a bulk run the run's own operation already groups the audit trail; otherwise this is one operation.
        Context::get('audit.operation_id') !== null ? $run() : $this->audit->operation('attendance', $label, $run, entityType: ShiftOverride::class);

        return $result;
    }

    /**
     * The days of the range the employee is employed on (joined, not gone, past pre-boarding), or why there are none.
     *
     * @return array{0: Carbon, 1: Carbon}|string
     */
    private function activeSpan(Employee $employee, Carbon $from, Carbon $to): array|string
    {
        $state = $employee->lifecycle_state?->value;
        if (! in_array($state, AttendanceMonthPopulation::states(), true)) {
            return 'Not active: the employee is '.str_replace('_', ' ', (string) $state).'.';
        }
        $first = $employee->joining_date !== null && $employee->joining_date->gt($from) ? $employee->joining_date->copy()->startOfDay() : $from->copy();
        $last = $employee->exit_date !== null && $employee->exit_date->lt($to) ? $employee->exit_date->copy()->startOfDay() : $to->copy();
        if ($first->gt($last)) {
            $when = $from->eq($to) ? 'on '.$from->format('d M Y') : 'on any day from '.$from->format('d M Y').' to '.$to->format('d M Y');

            return "Not active {$when}".($employee->joining_date?->gt($to) ? ' (joins '.$employee->joining_date->format('d M Y').').' : ($employee->exit_date?->lt($from) ? ' (left '.$employee->exit_date->format('d M Y').').' : '.'));
        }

        return [$first, $last];
    }

    /**
     * Y-m-d => the shift id of each day (null = weekly off).
     *
     * @param  list<string>  $weeklyOffDays
     * @return array<string, ?int>
     */
    private function pattern(Carbon $from, Carbon $to, ?Shift $shift, array $weeklyOffDays): array
    {
        $days = [];
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $days[$day->toDateString()] = in_array(strtolower($day->format('D')), $weeklyOffDays, true) ? null : $shift?->id;
        }

        return $days;
    }

    /** @param  array<string, ?int>  $days */
    private function write(Employee $employee, array $days, ?bool $autoShift, string $reason, ?User $actor, string $source): int
    {
        $written = 0;
        foreach ($days as $date => $shiftId) {
            $override = ShiftOverride::query()->where('employee_id', $employee->id)->whereDate('date', $date)->lockForUpdate()->first()
                ?? new ShiftOverride(['employee_id' => $employee->id, 'date' => Carbon::parse($date)]);
            $attributes = ['shift_id' => $shiftId, 'source' => $source, 'time_office_request_id' => null, 'reason' => $reason, 'created_by' => $actor?->id];
            // TX-5: the choice is stored when made, and a rostered day re-rostered without one loses an earlier choice.
            if ($autoShift !== null || ($override->getAttributes()['auto_shift'] ?? null) !== null) {
                $attributes['auto_shift'] = $autoShift;
            }
            $override->fill($attributes)->withAuditReason($reason)->save();
            $written++;
        }

        return $written;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function range(Carbon|string $from, Carbon|string $to): array
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay();
        if ($to->lt($from)) {
            throw new RuntimeException('The end date must not be before the start date.');
        }
        if ($from->diffInDays($to) > 92) {
            throw new RuntimeException('Roster at most three months at a time.');
        }

        return [$from, $to];
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('Give the reason for the roster change.');
        }

        return mb_substr($reason, 0, 255);
    }

    private function reprocess(Employee $employee, Carbon $from, Carbon $to): void
    {
        $end = $to->copy()->min(now()->startOfDay());
        for ($day = $from->copy(); $day->lte($end); $day->addDay()) {
            $this->processor->process($employee, $day->copy());
        }
    }

    private function assertUnlocked(Employee $employee, Carbon $from, Carbon $to): void
    {
        if ((bool) $this->settings->get('attendance.allow_corrections_after_lock', false)) {
            return;
        }
        // HROne parity TX-3: days of a closed attendance month are refused like locked days.
        app(AttendanceMonthCloses::class)->assertOpen($employee, $from, $to, "Part of this period is in a closed attendance month for {$employee->display_name}.");
        if (AttendanceRecord::query()->where('employee_id', $employee->id)->where('is_locked', true)->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())->exists()) {
            throw new RuntimeException("Part of this period is locked (finalized days) for {$employee->display_name}.");
        }
    }
}
