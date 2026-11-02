<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Jobs\ProcessAttendanceDay;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\EmployeeHolidayChange;
use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\HolidayLibraryEntry;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * HROne parity TX-12 ("Manage holiday" / global action "Change holiday", matrix 24 §24.7.2, §24.8 #10, §24.13 #5; owner
 * decision TIM-3 / D-TX3): HR gives, takes away or swaps a holiday for chosen employees, on top of their holiday calendar,
 * as dated exceptions per employee (EmployeeHolidayChange; none exist by default, so every holiday comes from the calendars
 * as before). HolidayResolver reads the live change of a day after the calendar and the change wins.
 *
 * - assign: the day becomes a holiday for the employee — picked from a holiday calendar or the holiday library, or typed
 *   (name and date), optionally a restricted holiday. Refused where the employee's calendar already gives a holiday.
 * - remove: the calendar holiday of that date stops being a holiday for the employee. Refused where they have none, and for
 *   a restricted holiday they chose (their restricted-holiday request is cancelled instead).
 * - replace: both — the calendar holiday is taken away and the new holiday given on another day (two rows, one change).
 *
 * Each employee is handled on their own and refused (with the reason) when outside the actor's access scope, for one's own
 * holidays, not employed on the day, a day before the back-dating limit (`attendance.holiday_change_backdate_months`,
 * default 2: from the first day of the month that many months back), a locked or closed-month day (unless corrections after
 * lock are allowed — then the day keeps its values and processing records the difference as an attendance arrear), or a
 * day already changed (undo that change first). The days already processed are recalculated through ProcessAttendanceDay.
 * An undo marks the change's rows undone with who, when and why (audited) and recalculates the days again.
 *
 * Who: holders of attendance.holiday_change (TX-12; attendance.manage also grants it); with the team option
 * `team.holiday_assign` (off by default) also a manager, for the people who report to them today.
 */
final class EmployeeHolidayChanges
{
    public const DEFAULT_BACKDATE_MONTHS = 2;

    public function __construct(
        private readonly HolidayResolver $resolver,
        private readonly SettingsRepository $settings,
        private readonly AuditRecorder $audit,
        private readonly AccessScopes $scopes,
    ) {}

    // --- limits and rights ------------------------------------------------------------------------------------------------

    /** Months before the current month a change may still reach (0 = from this month). */
    public function backdateMonths(): int
    {
        return max(0, min(24, (int) $this->settings->get('attendance.holiday_change_backdate_months', self::DEFAULT_BACKDATE_MONTHS)));
    }

    /** The first day a change may touch: the first day of the month backdateMonths() before the current one. */
    public function earliestDate(): Carbon
    {
        return now()->startOfMonth()->subMonthsNoOverflow($this->backdateMonths())->startOfDay();
    }

    /** The last day a change may touch: the end of next calendar year. */
    public function latestDate(): Carbon
    {
        return now()->addYearNoOverflow()->endOfYear()->startOfDay();
    }

    /** Managers may change their team's holidays (Setup → Time office; off by default). */
    public function teamOption(): bool
    {
        return (bool) $this->settings->get('team.holiday_assign', false);
    }

    /** Whether $actor holds the HR right (attendance.manage grants it too). */
    public function isHr(?User $actor): bool
    {
        return $actor !== null && $actor->hasPermission('attendance.holiday_change');
    }

    /** Whether $actor may use holiday changes at all: the HR right, or the team option with someone reporting to them. */
    public function mayUse(?User $actor): bool
    {
        if ($actor === null) {
            return false;
        }

        return $this->isHr($actor) || ($this->teamOption() && $this->teamQuery($actor)->exists());
    }

    /** Why $actor may not change $employee's holidays (null = they may). */
    public function refusal(?User $actor, Employee $employee): ?string
    {
        if ($actor === null) {
            return 'Sign in to change holidays.';
        }
        if ($employee->user_id !== null && (int) $employee->user_id === (int) $actor->id) {
            return 'You cannot change your own holidays.';
        }
        if (! $this->isHr($actor)) {
            if (! $this->teamOption()) {
                return 'You may not change holidays.';
            }
            if (! $this->manages($actor, $employee)) {
                return 'You may change holidays only for the people who report to you.';
            }
        }

        return $this->scopes->allows($actor, $employee) ? null : 'Outside your access scope.';
    }

    /** Whether $employee reports to $actor today (any reporting type). */
    public function manages(User $actor, Employee $employee): bool
    {
        return $this->teamQuery($actor)->whereKey($employee->getKey())->exists();
    }

    /** The people who report to $actor today (any reporting type). */
    public function teamQuery(User $actor): Builder
    {
        return Employee::query()->whereIn('employees.id', ReportingRelationship::query()->withoutGlobalScope(AccessScope::class)->currentlyEffective()
            ->whereIn('manager_id', Employee::query()->withoutGlobalScope(AccessScope::class)->select('id')->where('user_id', $actor->id))
            ->select('employee_id'));
    }

    /**
     * The employee's live changes touching days from $from to $to, by date (read by My holidays, the attendance calendar
     * and the roster board).
     *
     * @return Collection<string, EmployeeHolidayChange> Y-m-d => change
     */
    public function liveBetween(Employee $employee, Carbon $from, Carbon $to): Collection
    {
        return EmployeeHolidayChange::query()->where('employee_id', $employee->id)->live()
            ->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())
            ->orderBy('date')->orderBy('id')->get()->keyBy(fn (EmployeeHolidayChange $c) => $c->date->toDateString());
    }

    // --- the change, checked once ---------------------------------------------------------------------------------------

    /**
     * The form's answers, checked and written out the way apply() reads them (plain values, so a bulk run can store them).
     *
     * $data: action (assign | remove | replace); for remove / replace `remove_holiday_id` (a holiday of a holiday calendar —
     * its date is taken away); for assign / replace `source` (typed | calendar | library) with `holiday_id` (calendar),
     * `library_entry_id` (library) or `name` and `date` (typed), and `restricted`.
     *
     * @param  array<string, mixed>  $data
     * @return array{action: string, remove: ?array{date: string, name: string}, add: ?array{date: string, name: string, type: string, restricted: bool, source: string, holiday_id: ?int, holiday_library_entry_id: ?int}}
     */
    public function prepare(array $data): array
    {
        $action = (string) ($data['action'] ?? '');
        if (! array_key_exists($action, EmployeeHolidayChange::ACTIONS)) {
            throw new RuntimeException('Choose assign, remove or replace holiday.');
        }

        $remove = null;
        if (in_array($action, ['remove', 'replace'], true)) {
            $holiday = filled($data['remove_holiday_id'] ?? null) ? Holiday::query()->find((int) $data['remove_holiday_id']) : null;
            if (! $holiday instanceof Holiday) {
                throw new RuntimeException($action === 'remove' ? 'Choose the holiday to remove.' : 'Choose the holiday to replace.');
            }
            $this->assertWithinLimits($holiday->date);
            $remove = ['date' => $holiday->date->toDateString(), 'name' => (string) $holiday->name];
        }

        $add = null;
        if (in_array($action, ['assign', 'replace'], true)) {
            $add = $this->holidayToGive($data);
            if ($remove !== null && $remove['date'] === $add['date']) {
                throw new RuntimeException('The new holiday must be on another day than the one it replaces.');
            }
        }

        return ['action' => $action, 'remove' => $remove, 'add' => $add];
    }

    /** "Assign holiday: Founders' Day on Sat, 14 Nov 2026 (restricted)" — what a prepared change does, for people. */
    public static function describe(array $prepared): string
    {
        $day = fn (string $date) => Carbon::parse($date)->format('D, d M Y');
        $add = $prepared['add'] ?? null;
        $remove = $prepared['remove'] ?? null;
        $given = $add !== null ? $add['name'].' on '.$day($add['date']).($add['restricted'] ? ' (restricted)' : '') : '';

        return match ($prepared['action'] ?? null) {
            'assign' => 'Assign holiday: '.$given,
            'remove' => 'Remove holiday: the holiday on '.$day((string) $remove['date']).' ('.$remove['name'].')',
            'replace' => 'Replace holiday: the holiday on '.$day((string) $remove['date']).' ('.$remove['name'].') with '.$given,
            default => '',
        };
    }

    // --- applying it ------------------------------------------------------------------------------------------------------

    /**
     * Apply a prepared change (prepare()) to each employee on their own. One audited operation (or the caller's, inside a
     * bulk run). The days already processed are recalculated once the change is saved.
     *
     * @param  iterable<Employee>  $employees
     * @param  array<string, mixed>  $prepared
     * @return array{done: list<array{employee_id: int, label: string, message: string}>, refused: list<array{employee_id: int, label: string, reason: string, dates: ?string}>}
     */
    public function apply(iterable $employees, array $prepared, string $reason, ?User $actor = null): array
    {
        $actor ??= auth()->user();
        $reason = mb_substr(trim($reason), 0, 500);
        if ($reason === '') {
            throw new RuntimeException('Give the reason for the change.');
        }
        if ($actor !== null && ! $this->isHr($actor) && ! $this->teamOption()) {
            throw new RuntimeException('You may not change holidays.');
        }
        $prepared = $this->checked($prepared);

        $result = ['done' => [], 'refused' => []];
        $run = function () use ($employees, $prepared, $reason, $actor, &$result) {
            foreach ($employees as $employee) {
                $employee->loadMissing('person');
                $row = ['employee_id' => (int) $employee->id, 'label' => $employee->auditLabel()];
                try {
                    [$changes, $recalculated] = $this->applyTo($employee, $prepared, $reason, $actor);
                } catch (RuntimeException $e) {
                    $result['refused'][] = $row + ['reason' => $e->getMessage(), 'dates' => null];

                    continue;
                }
                $result['done'][] = $row + ['message' => implode('; ', array_map(fn (EmployeeHolidayChange $c) => $c->describe(), $changes))
                    .($recalculated > 0 ? '; '.$recalculated.' processed day(s) recalculated' : '').'.'];
            }

            return ['succeeded' => count($result['done']), 'failed' => count($result['refused'])];
        };

        Context::get('audit.operation_id') !== null
            ? $run()
            : $this->audit->operation('attendance', self::describe($prepared), $run, $reason, EmployeeHolidayChange::class);

        return $result;
    }

    /**
     * Undo a change: its rows (both rows of a replace) become undone with who, when and why, and the days already processed
     * are recalculated. The same refusals as a new change apply (rights, scope, back-dating limit, locked days).
     */
    public function undo(EmployeeHolidayChange $change, string $reason, ?User $actor = null): void
    {
        $actor ??= auth()->user();
        $reason = mb_substr(trim($reason), 0, 500);
        if ($reason === '') {
            throw new RuntimeException('Give the reason for undoing the change.');
        }
        if (! $change->isActive()) {
            throw new RuntimeException('This change was already undone.');
        }
        $change->loadMissing('employee');
        $employee = $change->employee ?? throw new RuntimeException('The employee of this change no longer exists.');
        if (($refusal = $this->refusal($actor, $employee)) !== null) {
            throw new RuntimeException($refusal);
        }
        $rows = EmployeeHolidayChange::query()->where('employee_id', $employee->id)->where('group_key', $change->group_key)->live()->orderBy('id')->get();
        foreach ($rows as $row) {
            $this->assertWithinLimits($row->date);
            $this->assertOpenDay($employee, $row->date);
        }

        DB::transaction(function () use ($rows, $reason, $actor) {
            foreach ($rows as $row) {
                $locked = EmployeeHolidayChange::query()->whereKey($row->id)->lockForUpdate()->first();
                if ($locked === null || ! $locked->isActive()) {
                    throw new RuntimeException('This change was already undone.');
                }
                $locked->fill(['status' => 'undone', 'active' => null, 'undone_by' => $actor?->id, 'undone_at' => now(), 'undo_reason' => $reason])
                    ->withAuditReason($reason)->save();
            }
        });

        $this->recalculate($employee, $rows->map(fn (EmployeeHolidayChange $r) => $r->date->toDateString())->all());
    }

    /**
     * One employee: checks, the rows, then the recalculation.
     *
     * @return array{0: list<EmployeeHolidayChange>, 1: int} [the rows written, processed days recalculated]
     */
    private function applyTo(Employee $employee, array $prepared, string $reason, ?User $actor): array
    {
        if (($refusal = $this->refusal($actor, $employee)) !== null) {
            throw new RuntimeException($refusal);
        }
        if (! in_array($employee->lifecycle_state?->value, AttendanceMonthPopulation::states(), true)) {
            throw new RuntimeException('Not employed: the employee is '.str_replace('_', ' ', (string) $employee->lifecycle_state?->value).'.');
        }

        $rows = [];
        if (($remove = $prepared['remove']) !== null) {
            $date = Carbon::parse($remove['date']);
            $this->assertDayOpenFor($employee, $date);
            $holiday = $this->resolver->calendarHolidayOn($employee, $date->copy());
            if ($holiday === null) {
                throw new RuntimeException('No holiday on '.$date->format('d M Y').' on the employee\'s holiday calendar.');
            }
            $holiday->loadMissing('calendar');
            if ($holiday->type === 'optional' && (bool) $holiday->calendar?->optional_requires_selection) {
                throw new RuntimeException($holiday->name.' on '.$date->format('d M Y').' is a restricted holiday the employee chose: cancel their restricted-holiday request instead.');
            }
            $rows[] = ['effect' => 'remove', 'date' => $date->toDateString(), 'name' => (string) $holiday->name, 'holiday_type' => (string) $holiday->type,
                'is_restricted' => false, 'source' => 'calendar', 'holiday_id' => $holiday->id, 'holiday_library_entry_id' => null];
        }
        if (($add = $prepared['add']) !== null) {
            $date = Carbon::parse($add['date']);
            $this->assertDayOpenFor($employee, $date);
            $existing = $this->resolver->calendarHolidayOn($employee, $date->copy());
            if ($existing !== null) {
                throw new RuntimeException('Already a holiday on '.$date->format('d M Y').' ('.$existing->name.').');
            }
            $rows[] = ['effect' => 'add', 'date' => $date->toDateString(), 'name' => $add['name'], 'holiday_type' => $add['type'], 'is_restricted' => (bool) $add['restricted'],
                'source' => $add['source'], 'holiday_id' => $add['holiday_id'], 'holiday_library_entry_id' => $add['holiday_library_entry_id']];
        }

        $group = (string) Str::ulid();
        try {
            $written = DB::transaction(function () use ($employee, $rows, $prepared, $group, $reason, $actor) {
                $written = [];
                foreach ($rows as $attributes) {
                    $change = new EmployeeHolidayChange($attributes + ['employee_id' => $employee->id, 'group_key' => $group, 'action' => $prepared['action'],
                        'reason' => $reason, 'status' => 'active', 'active' => true, 'created_by' => $actor?->id]);
                    $change->withAuditReason($reason)->save();
                    $written[] = $change;
                }

                return $written;
            });
        } catch (UniqueConstraintViolationException) {
            throw new RuntimeException('Already changed on that day: undo that change first.');
        }

        return [$written, $this->recalculate($employee, array_column($rows, 'date'))];
    }

    /** Employed on the day, within the limits, not locked or closed, and not already changed. */
    private function assertDayOpenFor(Employee $employee, Carbon $date): void
    {
        if ($employee->joining_date !== null && $employee->joining_date->copy()->startOfDay()->gt($date)) {
            throw new RuntimeException('Not employed on '.$date->format('d M Y').' (joins '.$employee->joining_date->format('d M Y').').');
        }
        if ($employee->exit_date !== null && $employee->exit_date->copy()->startOfDay()->lt($date)) {
            throw new RuntimeException('Not employed on '.$date->format('d M Y').' (left '.$employee->exit_date->format('d M Y').').');
        }
        $this->assertOpenDay($employee, $date);
        $earlier = EmployeeHolidayChange::query()->where('employee_id', $employee->id)->live()->onDay($date)->first();
        if ($earlier !== null) {
            throw new RuntimeException('Already changed on '.$date->format('d M Y').' ('.$earlier->describe().'): undo that change first.');
        }
    }

    /** Locked (finalized) days and days of a closed attendance month follow the lock rule. */
    private function assertOpenDay(Employee $employee, Carbon $date): void
    {
        if ((bool) $this->settings->get('attendance.allow_corrections_after_lock', false)) {
            return;
        }
        if (AttendanceRecord::query()->where('employee_id', $employee->id)->whereDate('date', $date->toDateString())->where('is_locked', true)->exists()) {
            throw new RuntimeException($date->format('d M Y').' is locked (finalized days).');
        }
        if (app(AttendanceMonthCloses::class)->isClosedDay($employee, $date)) {
            throw new RuntimeException($date->format('d M Y').' is in a closed attendance month.');
        }
    }

    /** Within the back-dating limit and no later than the end of next year. */
    private function assertWithinLimits(Carbon $date): void
    {
        $day = $date->copy()->startOfDay();
        $earliest = $this->earliestDate();
        if ($day->lt($earliest)) {
            throw new RuntimeException($day->format('d M Y').' is before '.$earliest->format('d M Y').': holidays further back cannot be changed here. Ask your administrator.');
        }
        if ($day->gt($this->latestDate())) {
            throw new RuntimeException('Choose a day up to '.$this->latestDate()->format('d M Y').'.');
        }
    }

    /**
     * The holiday to give: from a holiday calendar, the holiday library (published lists only) or typed.
     *
     * @param  array<string, mixed>  $data
     * @return array{date: string, name: string, type: string, restricted: bool, source: string, holiday_id: ?int, holiday_library_entry_id: ?int}
     */
    private function holidayToGive(array $data): array
    {
        $source = (string) ($data['source'] ?? 'typed');
        $restricted = filter_var($data['restricted'] ?? false, FILTER_VALIDATE_BOOLEAN);
        [$name, $date, $type, $holidayId, $entryId] = match ($source) {
            'calendar' => (function () use ($data) {
                $holiday = filled($data['holiday_id'] ?? null) ? Holiday::query()->find((int) $data['holiday_id']) : null;
                if (! $holiday instanceof Holiday) {
                    throw new RuntimeException('Choose the holiday from a holiday calendar.');
                }

                return [(string) $holiday->name, $holiday->date->copy(), (string) $holiday->type, (int) $holiday->id, null];
            })(),
            'library' => (function () use ($data) {
                $entry = filled($data['library_entry_id'] ?? null) ? HolidayLibraryEntry::query()->whereHas('libraryList', fn ($q) => $q->published())->find((int) $data['library_entry_id']) : null;
                if (! $entry instanceof HolidayLibraryEntry) {
                    throw new RuntimeException('Choose the holiday from the holiday library.');
                }

                return [(string) $entry->name, $entry->date->copy(), (string) $entry->type, null, (int) $entry->id];
            })(),
            'typed' => (function () use ($data) {
                $name = trim((string) ($data['name'] ?? ''));
                if ($name === '') {
                    throw new RuntimeException('Give the holiday a name.');
                }
                try {
                    $date = filled($data['date'] ?? null) ? Carbon::parse((string) $data['date']) : null;
                } catch (Throwable) {
                    $date = null;
                }
                if ($date === null) {
                    throw new RuntimeException('Choose the holiday date.');
                }

                return [mb_substr($name, 0, 150), $date, 'company', null, null];
            })(),
            default => throw new RuntimeException('Choose where the holiday comes from: a holiday calendar, the holiday library or typed.'),
        };
        $this->assertWithinLimits($date);

        return [
            'date' => $date->toDateString(), 'name' => $name,
            'type' => $restricted ? 'optional' : (in_array($type, ['public', 'company'], true) ? $type : 'company'),
            'restricted' => $restricted, 'source' => $source, 'holiday_id' => $holidayId, 'holiday_library_entry_id' => $entryId,
        ];
    }

    /**
     * A prepared change (prepare(); a bulk run stores it and applies it later) checked again: its shape, and the days
     * against today's limits.
     *
     * @param  array<string, mixed>  $prepared
     * @return array{action: string, remove: ?array{date: string, name: string}, add: ?array{date: string, name: string, type: string, restricted: bool, source: string, holiday_id: ?int, holiday_library_entry_id: ?int}}
     */
    private function checked(array $prepared): array
    {
        $action = (string) ($prepared['action'] ?? '');
        $remove = $prepared['remove'] ?? null;
        $add = $prepared['add'] ?? null;
        $wantsRemove = in_array($action, ['remove', 'replace'], true);
        $wantsAdd = in_array($action, ['assign', 'replace'], true);
        if (! array_key_exists($action, EmployeeHolidayChange::ACTIONS) || $wantsRemove !== is_array($remove) || $wantsAdd !== is_array($add)
            || ($wantsRemove && blank($remove['date'] ?? null)) || ($wantsAdd && (blank($add['date'] ?? null) || blank($add['name'] ?? null)))) {
            throw new RuntimeException('Choose the holiday change again.');
        }
        foreach (array_filter([$remove['date'] ?? null, $add['date'] ?? null]) as $date) {
            $this->assertWithinLimits(Carbon::parse((string) $date));
        }

        return [
            'action' => $action,
            'remove' => $wantsRemove ? ['date' => Carbon::parse((string) $remove['date'])->toDateString(), 'name' => (string) ($remove['name'] ?? '')] : null,
            'add' => $wantsAdd ? [
                'date' => Carbon::parse((string) $add['date'])->toDateString(), 'name' => mb_substr((string) $add['name'], 0, 150),
                'type' => in_array($add['type'] ?? null, ['public', 'company', 'optional'], true) ? (string) $add['type'] : 'company',
                'restricted' => (bool) ($add['restricted'] ?? false),
                'source' => array_key_exists((string) ($add['source'] ?? ''), EmployeeHolidayChange::SOURCES) ? (string) $add['source'] : 'typed',
                'holiday_id' => filled($add['holiday_id'] ?? null) ? (int) $add['holiday_id'] : null,
                'holiday_library_entry_id' => filled($add['holiday_library_entry_id'] ?? null) ? (int) $add['holiday_library_entry_id'] : null,
            ] : null,
        ];
    }

    /**
     * Recalculate the employee's days already processed among $dates (up to today), through the processing job.
     *
     * @param  list<string>  $dates
     */
    private function recalculate(Employee $employee, array $dates): int
    {
        $today = now()->toDateString();
        $dates = array_values(array_unique(array_filter($dates, fn (string $d) => $d <= $today)));
        if ($dates === []) {
            return 0;
        }
        $processed = AttendanceRecord::query()->where('employee_id', $employee->id)
            ->where(fn (Builder $q) => collect($dates)->each(fn (string $d) => $q->orWhereDate('date', $d)))
            ->pluck('date')->map(fn ($d) => Carbon::parse($d)->toDateString())->unique()->values();
        foreach ($processed as $date) {
            ProcessAttendanceDay::dispatch((int) $employee->id, $date, 'holiday_change')->afterCommit();
        }

        return $processed->count();
    }
}
