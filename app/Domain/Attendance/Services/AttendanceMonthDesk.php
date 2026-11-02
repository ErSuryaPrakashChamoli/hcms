<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Contracts\AttendanceCycle;
use App\Domain\Attendance\Models\AttendanceArrear;
use App\Domain\Attendance\Models\AttendanceMonthClose;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\ShiftOverride;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Lifecycle\Enums\LifecycleState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

/**
 * HROne parity TX-3 (matrix 24 §24.5.3, "Run attendance"): what the month desk shows for a company's attendance month —
 * read only, inside the viewer's access scope.
 *
 * - Step 1 (pre-verification): absent and half days of the month.
 * - Step 2 (arrears): corrections to earlier months that the month would take (pending) or that HR held, by the month
 *   they correct.
 * - Step 3 (processing): the month's people, how many have every day so far processed, the latest background runs.
 * - Readiness ("Please review following errors"): no attendance policy, last month not closed / not processed, no
 *   work schedule or roster, joined after the cycle's cut-off. Reminders: requests still waiting on the month's days.
 *
 * Every list can be downloaded as CSV (cells safe from spreadsheet formulas), audited as an attendance export.
 */
final class AttendanceMonthDesk
{
    /** List key => [label, what it lists]. */
    public const LISTS = [
        'absences' => ['Absent and half days', 'Days of the month processed as absent or half day.'],
        'unprocessed' => ['Not fully processed', 'Employees with days of the month so far (up to yesterday) that have no processed attendance.'],
        'policy_missing' => ['Attendance policy missing', 'No attendance policy applies to the employee: the built-in defaults are used.'],
        'previous_month' => ['Last month attendance not processed', 'Employees with days of last month that have no processed attendance.'],
        'no_schedule' => ['Employees with missing shift', 'No work schedule (assigned or by rule) and no rostered day in the month.'],
        'late_joiners' => ['Joined after the cut-off', 'Joined after this month\'s last day but within its calendar month: their first days belong to next month.'],
        'pending_regularisation' => ['Attendance regularisation requests', 'Waiting for a decision on days of the month.'],
        'pending_leave' => ['Leave requests', 'Waiting for a decision (or a cancellation) on days of the month.'],
        'pending_on_duty' => ['On duty requests', 'Waiting for a decision (or a cancellation) on days of the month.'],
        'pending_restricted_holiday' => ['Restricted holiday requests', 'Waiting for a decision (or a cancellation) on days of the month.'],
        'pending_short_leave' => ['Short leave requests', 'Waiting for a decision (or a cancellation) on days of the month.'],
        'pending_other' => ['Shift, week-off, overtime and comp-off requests', 'Waiting for a decision (or a cancellation) on days of the month.'],
    ];

    public const READINESS = ['policy_missing', 'previous_month', 'no_schedule', 'late_joiners'];

    public function __construct(
        private readonly AttendanceCycle $cycle,
        private readonly AttendanceMonthCloses $closes,
        private readonly AttendanceMonthPopulation $population,
        private readonly AttendanceMonthRuns $runs,
        private readonly AttendanceOutput $output,
        private readonly PolicyResolver $policies,
        private readonly ShiftResolver $shifts,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * The months to choose from for a company: twelve back to one ahead of the month holding today.
     *
     * @return array<string, string> 'YYYY-MM' => "Oct 2026 · 25 Sep – 24 Oct"
     */
    public function months(int $companyId, int $back = 12, int $ahead = 1): array
    {
        [$year, $month] = $this->cycle->monthOf($companyId, today());
        $out = [];
        for ($i = $ahead; $i >= -$back; $i--) {
            $first = CarbonImmutable::create($year, $month, 1)->addMonthsNoOverflow($i);
            try {
                [$start, $end] = $this->cycle->window($companyId, $first->year, $first->month);
            } catch (RuntimeException) {
                continue;
            }
            $out[$first->format('Y-m')] = $first->format('M Y').' · '.$start->format('j M').' – '.$end->format('j M');
        }

        return $out;
    }

    /** @return array{0: int, 1: int} the month holding today for the company */
    public function currentMonth(int $companyId): array
    {
        return $this->cycle->monthOf($companyId, today());
    }

    /**
     * Everything the desk shows for a company's month (the lists themselves come from list()).
     *
     * @return array<string, mixed>
     */
    public function overview(int $companyId, int $year, int $month): array
    {
        [$start, $end] = $this->closes->window($companyId, $year, $month);
        $label = Carbon::create($year, $month, 1)->format('M Y');
        $employees = $this->population->query($companyId, $start, $end)->with('person')->orderBy('employee_code')->get();
        $ids = $employees->pluck('id')->all();
        $until = $end->min(CarbonImmutable::yesterday());
        $record = $this->closes->record($companyId, $year, $month);

        $absenceQuery = fn () => AttendanceRecord::query()->whereIn('employee_id', $ids)->whereIn('status', ['absent', 'half_day'])
            ->whereDate('date', '>=', $start->toDateString())->whereDate('date', '<=', $end->toDateString());
        $absences = $ids === [] ? collect() : $absenceQuery()->toBase()->selectRaw('status, count(*) as days')->groupBy('status')->pluck('days', 'status');
        $absentPeople = $ids === [] ? 0 : $absenceQuery()->distinct()->count('employee_id');

        $unprocessed = $this->unprocessedRows($employees, $start, $until);
        $pending = array_map('count', $this->population->pending($ids, $start, $end));
        $lists = [];
        foreach (self::READINESS as $key) {
            $lists[$key] = count($this->rows($key, $companyId, $year, $month));
        }

        return [
            'company_id' => $companyId, 'year' => $year, 'month' => $month, 'label' => $label,
            'start' => $start, 'end' => $end, 'until' => $until, 'started' => $start->lte(today()), 'ended' => $end->lt(today()),
            'close' => [
                'enabled' => $this->closes->enabled(),
                'status' => $this->closes->enabled() && $record?->isClosed() ? 'closed' : 'open',
                'closed_at' => $record?->closed_at, 'closed_by' => $record?->closed_by ? $record->closer()->value('name') : null, 'note' => $record?->close_note,
                'reopened_at' => $record?->reopened_at, 'reopened_by' => $record?->reopened_by ? $record->reopener()->value('name') : null, 'reopen_reason' => $record?->reopen_reason,
                'final' => $this->cycle->isFinal($companyId, $year, $month),
                'blockers' => $this->closes->enabled() && ! $record?->isClosed() ? $this->closes->closeBlockers($companyId, $year, $month) : [],
                'window_changed' => $record?->isClosed() && (! $record->start_date->isSameDay($start) || ! $record->end_date->isSameDay($end)),
            ],
            'absences' => [
                'absent_days' => (int) ($absences['absent'] ?? 0), 'half_days' => (int) ($absences['half_day'] ?? 0), 'people' => (int) $absentPeople,
            ],
            'arrears' => $this->arrearGroups($companyId, $ids, $start),
            'processing' => [
                'total' => count($ids), 'complete' => count($ids) - count($unprocessed), 'incomplete' => count($unprocessed),
                'missing_days' => (int) array_sum(array_column($unprocessed, 'days')), 'runs' => $this->runs->latest($companyId, $year, $month),
            ],
            'readiness' => $lists,
            'pending' => $pending,
        ];
    }

    /**
     * Pending and held arrears the month would take, by the month of the corrected day (only days before the month).
     *
     * @param  list<int>  $employeeIds
     * @return list<array{key: string, label: string, start: string, end: string, pending: int, held: int, pending_days: float, held_days: float, people: int}>
     */
    public function arrearGroups(int $companyId, array $employeeIds, CarbonImmutable $before): array
    {
        if ($employeeIds === []) {
            return [];
        }
        $rows = AttendanceArrear::query()->whereIn('employee_id', $employeeIds)->whereIn('status', ['pending', 'held'])
            ->whereDate('date', '<', $before->toDateString())->orderBy('date')->get(['id', 'employee_id', 'date', 'status', 'delta_days']);
        $monthOf = [];
        $groups = [];
        foreach ($rows as $arrear) {
            $day = $arrear->date->toDateString();
            $monthOf[$day] ??= $this->cycle->monthOf($companyId, $arrear->date);
            [$y, $m] = $monthOf[$day];
            $key = sprintf('%04d-%02d', $y, $m);
            if (! isset($groups[$key])) {
                [$start, $end] = $this->safeWindow($companyId, $y, $m) ?? [$arrear->date, $arrear->date];
                $groups[$key] = ['key' => $key, 'label' => Carbon::create($y, $m, 1)->format('M Y'), 'start' => $start->toDateString(), 'end' => $end->toDateString(),
                    'pending' => 0, 'held' => 0, 'pending_days' => 0.0, 'held_days' => 0.0, 'people' => []];
            }
            $groups[$key][$arrear->status]++;
            $groups[$key][$arrear->status.'_days'] += (float) $arrear->delta_days;
            $groups[$key]['people'][(int) $arrear->employee_id] = true;
        }

        return array_values(array_map(fn (array $g) => ['people' => count($g['people']), 'pending_days' => round($g['pending_days'], 2), 'held_days' => round($g['held_days'], 2)] + $g, $groups));
    }

    /**
     * One list of the desk as columns and rows (inside the viewer's access scope).
     *
     * @return array{columns: array<string, string>, rows: list<array<string, mixed>>}
     */
    public function list(string $key, int $companyId, int $year, int $month): array
    {
        if (! array_key_exists($key, self::LISTS)) {
            throw new RuntimeException('Unknown list.');
        }
        $base = ['employee_code' => 'Employee code', 'name' => 'Employee'];
        $columns = match ($key) {
            'absences' => $base + ['date' => 'Date', 'status' => 'Attendance status', 'type' => 'Type'],
            'unprocessed', 'previous_month' => $base + ['days' => 'Days without attendance', 'detail' => 'Why'],
            'late_joiners' => $base + ['joined' => 'Joined', 'detail' => 'Why'],
            default => str_starts_with($key, 'pending_') ? $base + ['what' => 'Request', 'dates' => 'Days', 'status' => 'Status'] : $base + ['detail' => 'Why'],
        };

        return ['columns' => $columns, 'rows' => $this->rows($key, $companyId, $year, $month)];
    }

    public function csv(string $key, User $actor, int $companyId, int $year, int $month): string
    {
        $list = $this->list($key, $companyId, $year, $month);
        $out = fopen('php://temp', 'w+');
        fputcsv($out, array_values($list['columns']));
        foreach ($list['rows'] as $row) {
            fputcsv($out, array_map(fn (string $column) => self::cell($row[$column] ?? null), array_keys($list['columns'])));
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        $label = Carbon::create($year, $month, 1)->format('M Y');
        $this->audit->record(AuditAction::Export, 'attendance', null, [], null, actor: $actor, entityLabel: 'Run attendance: '.self::LISTS[$key][0].' ('.$label.')', metadata: [
            'export' => 'run_attendance', 'list' => $key, 'rows' => count($list['rows']), 'company_id' => $companyId, 'month' => sprintf('%04d-%02d', $year, $month),
        ]);

        return $csv;
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $key, int $companyId, int $year, int $month): array
    {
        [$start, $end] = $this->closes->window($companyId, $year, $month);
        $employees = fn () => $this->population->query($companyId, $start, $end)->with('person')->orderBy('employee_code')->get();

        if (str_starts_with($key, 'pending_')) {
            $people = $employees()->keyBy('id');
            $kind = substr($key, strlen('pending_'));

            return array_map(fn (array $r) => self::who($people[$r['employee_id']] ?? null) + ['what' => $r['what'], 'dates' => $r['dates'], 'status' => $r['status']],
                $this->population->pending($people->keys()->all(), $start, $end)[$kind] ?? []);
        }

        return match ($key) {
            'absences' => $this->absenceRows($employees(), $start, $end),
            'unprocessed' => $this->unprocessedRows($employees(), $start, $end->min(CarbonImmutable::yesterday())),
            'policy_missing' => $this->policyMissing($employees(), $start, $end),
            'previous_month' => $this->previousMonth($companyId, $year, $month),
            'no_schedule' => $this->noSchedule($employees(), $start, $end),
            'late_joiners' => $this->lateJoiners($companyId, $year, $month, $end),
        };
    }

    /** @return list<array<string, mixed>> */
    private function absenceRows(Collection $employees, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $people = $employees->keyBy('id');
        if ($people->isEmpty()) {
            return [];
        }

        return AttendanceRecord::query()->whereIn('employee_id', $people->keys()->all())->whereIn('status', ['absent', 'half_day'])
            ->whereDate('date', '>=', $start->toDateString())->whereDate('date', '<=', $end->toDateString())->orderBy('date')->get(['employee_id', 'date', 'status', 'exceptions'])
            ->map(fn (AttendanceRecord $r) => self::who($people[$r->employee_id] ?? null) + ['date' => $r->date->toDateString(),
                'status' => $r->status === 'half_day' ? 'Half day' : 'Absent', 'type' => implode(', ', array_map(fn ($e) => str_replace('_', ' ', (string) $e), (array) ($r->exceptions ?? [])))])
            ->sortBy(['employee_code', 'date'])->values()->all();
    }

    /** @return list<array<string, mixed>> employees with days so far (inside joining and exit) without a processed record */
    private function unprocessedRows(Collection $employees, CarbonImmutable $start, CarbonImmutable $until): array
    {
        if ($until->lt($start) || $employees->isEmpty()) {
            return [];
        }
        $processed = $this->output->processedDates($employees->pluck('id')->all(), $start, $until);
        $rows = [];
        foreach ($employees as $employee) {
            $from = $employee->joining_date ? $start->max(CarbonImmutable::parse($employee->joining_date)->startOfDay()) : $start;
            $to = $employee->exit_date ? $until->min(CarbonImmutable::parse($employee->exit_date)->startOfDay()) : $until;
            if ($to->lt($from)) {
                continue;
            }
            $have = array_filter($processed[(int) $employee->id] ?? [], fn (string $d) => $d >= $from->toDateString() && $d <= $to->toDateString());
            $span = (int) $from->diffInDays($to) + 1;
            $missing = $span - count($have);
            if ($missing > 0) {
                $rows[] = self::who($employee) + ['days' => $missing, 'detail' => "{$missing} of {$span} days up to {$to->format('j M')} have no processed attendance"];
            }
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function policyMissing(Collection $employees, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $on = $end->min(CarbonImmutable::today())->max($start);
        $rows = [];
        foreach ($employees as $employee) {
            if ($this->policies->resolve('attendance', $employee, $on) === null) {
                $rows[] = self::who($employee) + ['detail' => 'No attendance policy'.($this->policies->resolve('time_office', $employee, $on) === null ? ' and no time-office policy' : '').' applies on '.$on->format('j M Y')];
            }
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> last month: not closed (with month close on) and employees with days not processed */
    private function previousMonth(int $companyId, int $year, int $month): array
    {
        $previous = CarbonImmutable::create($year, $month, 1)->subMonthNoOverflow();
        $window = $this->safeWindow($companyId, $previous->year, $previous->month);
        if ($window === null) {
            return [];
        }
        [$start, $end] = $window;
        $rows = $this->unprocessedRows($this->population->query($companyId, $start, $end)->with('person')->orderBy('employee_code')->get(), $start, $end->min(CarbonImmutable::yesterday()));
        if ($this->closes->enabled() && ! $this->closes->isClosed($companyId, $previous->year, $previous->month) && ! $this->cycle->isFinal($companyId, $previous->year, $previous->month)) {
            array_unshift($rows, ['employee_code' => '', 'name' => '', 'days' => null, 'detail' => $previous->format('M Y').' is not closed yet']);
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function noSchedule(Collection $employees, CarbonImmutable $start, CarbonImmutable $end): array
    {
        if ($employees->isEmpty()) {
            return [];
        }
        $rostered = array_flip(ShiftOverride::query()->whereIn('employee_id', $employees->pluck('id')->all())
            ->whereDate('date', '>=', $start->toDateString())->whereDate('date', '<=', $end->toDateString())->distinct()->pluck('employee_id')->map(fn ($id) => (int) $id)->all());
        $on = Carbon::parse($end->min(CarbonImmutable::today())->max($start)->toDateString());
        $rows = [];
        foreach ($employees as $employee) {
            if (! isset($rostered[(int) $employee->id]) && $this->shifts->scheduleFor($employee, $on->copy())[0] === null) {
                $rows[] = self::who($employee) + ['detail' => 'No work schedule on '.$on->format('j M Y').' and no rostered day in the month'];
            }
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> joined after the month's last day, within its calendar month */
    private function lateJoiners(int $companyId, int $year, int $month, CarbonImmutable $end): array
    {
        $calendarEnd = CarbonImmutable::create($year, $month, 1)->endOfMonth()->startOfDay();
        if ($end->gte($calendarEnd)) {
            return [];
        }

        return $this->population->query($companyId, $end->addDay(), $calendarEnd)->with('person')
            ->whereDate('joining_date', '>', $end->toDateString())->whereDate('joining_date', '<=', $calendarEnd->toDateString())
            ->orderBy('joining_date')->get()
            ->map(fn (Employee $e) => self::who($e) + ['joined' => $e->joining_date?->toDateString(), 'detail' => 'Joined after '.$end->format('j M').': the first days are in next month'])
            ->all();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable}|null */
    private function safeWindow(int $companyId, int $year, int $month): ?array
    {
        try {
            return $this->cycle->window($companyId, $year, $month);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{employee_id: ?int, employee_code: string, name: string} */
    private static function who(?Employee $employee): array
    {
        return ['employee_id' => $employee?->id, 'employee_code' => (string) ($employee?->employee_code ?? ''), 'name' => (string) ($employee?->person?->display_name ?? '')];
    }

    /** A CSV cell that a spreadsheet will not run as a formula. */
    private static function cell(mixed $value): string
    {
        $text = is_float($value) ? number_format($value, 2, '.', '') : (string) ($value ?? '');

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$text : $text;
    }

    /** States a month's people can be in (for the population note on the page). */
    public static function stateLabels(): string
    {
        return implode(', ', array_map(fn (string $s) => LifecycleState::from($s)->getLabel(), AttendanceMonthPopulation::states()));
    }

    /** The month's absence query for the desk's table (scoped; narrowed by the table's filters). */
    public function absenceQuery(int $companyId, int $year, int $month): Builder
    {
        [$start, $end] = $this->closes->window($companyId, $year, $month);

        return AttendanceRecord::query()->whereIn('status', ['absent', 'half_day'])
            ->whereDate('date', '>=', $start->toDateString())->whereDate('date', '<=', $end->toDateString())
            ->whereIn('employee_id', $this->population->query($companyId, $start, $end)->select('employees.id'));
    }

    /** Closed-month row of a company's month (for the page header). */
    public function closeRecord(int $companyId, int $year, int $month): ?AttendanceMonthClose
    {
        return $this->closes->record($companyId, $year, $month);
    }
}
