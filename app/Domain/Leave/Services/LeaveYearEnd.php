<?php

namespace App\Domain\Leave\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Platform\Services\SettingsRepository;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use RuntimeException;

/**
 * HROne parity TX-8a (matrix 24 §24.6.6, global action "Carry forward leaves"; §24.1 #22 the HR-desk alert): the
 * year-end screen's reads and its posting. The figures are LeaveAccrual::yearEnd() — the calculation the year close
 * (`peopleos:leave:accrue --close-year`, and the daily run's close of the previous year) posts — read without writing,
 * and the posting is LeaveAccrual::closeYear() for the chosen employees and leave types. So the preview is what gets
 * posted, the ledger keys (`cf-{year}`, `lapse-{year}`) keep it idempotent (a second post writes nothing), and the
 * audit events are the year close's own.
 *
 * "Pending" = an employed employee with a closing balance in a leave type a leave policy carries and no carry-forward or
 * lapse entry for that year. Balances no active leave policy lists (comp-off credits, for one) are never carried by the
 * year close, so they are not pending either.
 */
final class LeaveYearEnd
{
    /** The right to post (HROne parity TX-2; leave.manage still grants it through permission_splits). */
    public const PERMISSION = 'leave.carry_forward';

    public const STATUSES = [
        'pending' => 'To carry forward',
        'posted' => 'Carried forward',
        'not_in_policy' => 'Not in the leave policy',
    ];

    /** @var array<int, array<string, LeaveType>> leave year => code => type (this instance's reads) */
    private array $typesMemo = [];

    public function __construct(
        private readonly LeaveAccrual $accrual,
        private readonly LeaveBalances $balances,
        private readonly LeaveEntitlements $entitlements,
        private readonly LeaveYear $years,
        private readonly AccessScopes $scopes,
        private readonly AuditRecorder $audit,
        private readonly SettingsRepository $settings,
    ) {}

    /** @return array{0: string, 1: string} the year close's ledger keys: the carry into the next year and the lapse */
    public static function keys(int $period): array
    {
        return ["cf-{$period}", "lapse-{$period}"];
    }

    /** "12", "7.5", "0.25" */
    public static function days(float $days): string
    {
        $text = number_format($days, 2, '.', '');
        $text = rtrim(rtrim($text, '0'), '.');

        return $text === '' || $text === '-0' ? '0' : $text;
    }

    /** The people the year close takes — employed, as the daily run and --close-year — inside the viewer's access scope. */
    public function population(): Builder
    {
        return Employee::query()->employed();
    }

    public function currentPeriod(): int
    {
        return $this->years->periodFor(now());
    }

    /** "2026" for a calendar leave year, "2026–27" for one that starts in another month. */
    public function name(int $period): string
    {
        return $this->years->startMonth() === 1 ? (string) $period : $period.'–'.substr((string) ($period + 1), -2);
    }

    /** "2026 · 1 Jan 2026 – 31 Dec 2026" */
    public function label(int $period): string
    {
        return $this->name($period).' · '.$this->years->start($period)->format('j M Y').' – '.$this->years->end($period)->format('j M Y');
    }

    public function endsOn(int $period): Carbon
    {
        return $this->years->end($period)->copy()->startOfDay();
    }

    public function yearEnded(int $period): bool
    {
        return $this->years->end($period)->lt(now());
    }

    /** @return array<int, string> leave year => label, the current one first, back to the earliest year in the ledger (at most ten) */
    public function yearOptions(): array
    {
        $current = $this->currentPeriod();
        $earliest = LeaveLedgerEntry::query()->min('period_year');
        $earliest = max(min($earliest === null ? $current - 1 : (int) $earliest, $current - 1), $current - 9);
        $out = [];
        for ($period = $current; $period >= $earliest; $period--) {
            $out[$period] = $this->label($period);
        }

        return $out;
    }

    /** The year the screen opens on: the last one that ended while it still has people to carry forward, else the current one. */
    public function defaultPeriod(): int
    {
        $current = $this->currentPeriod();

        return $this->pendingCount($current - 1) > 0 ? $current - 1 : $current;
    }

    /**
     * Leave types an active leave policy carries on the last day of the year (the types the year close takes), in the
     * leave types' order.
     *
     * @return array<string, LeaveType> code => type
     */
    public function types(int $period): array
    {
        if (array_key_exists($period, $this->typesMemo)) {
            return $this->typesMemo[$period];
        }
        $end = $this->years->end($period);
        $codes = [];
        foreach (Policy::query()->where('type', 'leave')->where('status', 'active')->orderBy('id')->get() as $policy) {
            foreach ($policy->versionEffectiveOn($end)?->setting('entitlements', []) ?? [] as $row) {
                if (! empty($row['leave_type_code'])) {
                    $codes[(string) $row['leave_type_code']] = true;
                }
            }
        }

        $types = [];
        foreach (LeaveType::query()->whereIn('code', array_keys($codes))->orderBy('sort_order')->orderBy('code')->get() as $type) {
            $types[(string) $type->code] = $type;
        }

        return $this->typesMemo[$period] = $types;
    }

    /**
     * @param  list<string>|null  $codes  null = every type a leave policy carries
     * @return list<int> the leave type ids of those codes (only types a leave policy carries)
     */
    public function typeIds(int $period, ?array $codes = null): array
    {
        $types = $this->types($period);
        if ($codes !== null) {
            $types = array_intersect_key($types, array_flip(array_map('strval', $codes)));
        }

        return array_values(array_map(fn (LeaveType $type) => (int) $type->id, $types));
    }

    /** @return array<int, string> active leave policies (id => name) */
    public function policyOptions(): array
    {
        return Policy::query()->where('type', 'leave')->where('status', 'active')->orderBy('name')->pluck('name', 'id')->map(fn ($name) => (string) $name)->all();
    }

    // --- Who: sub-queries of employee ids --------------------------------------------------------------------------------

    /**
     * Employees with a positive closing balance in one of the types (the period's ledger total: the closing balance
     * LeaveBalances works out).
     *
     * @param  list<int>  $typeIds
     */
    public function withBalance(int $period, array $typeIds): Builder
    {
        return LeaveLedgerEntry::query()->select('employee_id')->where('period_year', $period)->whereIn('leave_type_id', $typeIds)
            ->groupBy('employee_id', 'leave_type_id')->havingRaw('SUM(days) > 0');
    }

    /**
     * Employees with a year-end entry (carried forward or lapsed) for the year in one of the types.
     *
     * @param  list<int>  $typeIds
     */
    public function posted(int $period, array $typeIds): Builder
    {
        return LeaveLedgerEntry::query()->select('employee_id')->whereIn('accrual_key', self::keys($period))->whereIn('leave_type_id', $typeIds);
    }

    /**
     * Employees still to carry forward: a positive closing balance in one of the types and no year-end entry for that
     * type and year.
     *
     * @param  list<int>  $typeIds
     */
    public function pending(int $period, array $typeIds): Builder
    {
        $table = (new LeaveLedgerEntry)->getTable();

        return LeaveLedgerEntry::query()->select("{$table}.employee_id")
            ->where("{$table}.period_year", $period)->whereIn("{$table}.leave_type_id", $typeIds)
            ->whereNotExists(fn (QueryBuilder $done) => $done->selectRaw('1')->from("{$table} as year_end")
                ->whereColumn('year_end.employee_id', "{$table}.employee_id")
                ->whereColumn('year_end.leave_type_id', "{$table}.leave_type_id")
                ->whereIn('year_end.accrual_key', self::keys($period)))
            ->groupBy("{$table}.employee_id", "{$table}.leave_type_id")->havingRaw("SUM({$table}.days) > 0");
    }

    /**
     * "Pending actions": employed employees (in the viewer's access scope) still to carry forward for the year.
     *
     * @param  list<int>|null  $typeIds  null = every type a leave policy carries
     */
    public function pendingCount(int $period, ?array $typeIds = null): int
    {
        $typeIds ??= $this->typeIds($period);

        return $typeIds === [] ? 0 : $this->population()->whereIn('employees.id', $this->pending($period, $typeIds))->count();
    }

    /**
     * Employees whose leave policy on the last day of the year is $policyId (the policy is resolved per employee, as the
     * year close resolves it).
     *
     * @return list<int>
     */
    public function onPolicy(Builder $employees, int $policyId, int $period): array
    {
        $end = $this->years->end($period);
        $ids = [];
        (clone $employees)->reorder()->with('person')->chunkById(200, function ($chunk) use ($policyId, $end, &$ids) {
            foreach ($chunk as $employee) {
                if ((int) ($this->entitlements->policyFor($employee, $end)?->policy_id ?? 0) === $policyId) {
                    $ids[] = (int) $employee->id;
                }
            }
        }, 'employees.id', 'id');

        return $ids;
    }

    // --- The preview -----------------------------------------------------------------------------------------------------

    /**
     * For each employee, one line per leave type ($codes, else every type a leave policy carries) with a closing balance
     * or a year-end entry. A line still to carry forward ("pending") shows what closeYear() posts — the same
     * LeaveAccrual::yearEnd calculation, read for all the employees at once and written nowhere; a line carried forward
     * ("posted") shows what was posted; a balance in a type the employee's own leave policy does not list
     * ("not_in_policy") is left by the year close as it is.
     *
     * @param  iterable<Employee>  $employees
     * @param  list<string>|null  $codes
     * @return array<int, array{policy_id: ?int, policy: ?string, lines: list<array{type_id: int, code: string, type: string, closing: float, carry: float, lapse: float, status: string}>}>
     */
    public function preview(iterable $employees, int $period, ?array $codes = null): array
    {
        $byEmployee = [];
        foreach ($employees as $employee) {
            $byEmployee[(int) $employee->id] = $employee;
        }
        if ($byEmployee === []) {
            return [];
        }

        $types = $this->types($period);
        if ($codes !== null) {
            $types = array_intersect_key($types, array_flip(array_map('strval', $codes)));
        }
        $byTypeId = [];
        foreach ($types as $type) {
            $byTypeId[(int) $type->id] = $type;
        }
        $ids = array_keys($byEmployee);
        $closings = $this->balances->closings($ids, $period);
        $posted = $this->postedFigures($ids, $period);
        $policies = Policy::query()->where('type', 'leave')->pluck('name', 'id')->all();
        $end = $this->years->end($period);
        $order = array_flip(array_keys($byTypeId));

        $out = [];
        foreach ($byEmployee as $id => $employee) {
            $version = $this->entitlements->policyFor($employee, $end);
            $line = fn (LeaveType $type, float $closing, float $carry, float $lapse, string $status) => [
                'type_id' => (int) $type->id, 'code' => (string) $type->code, 'type' => (string) $type->name,
                'closing' => $closing, 'carry' => $carry, 'lapse' => $lapse, 'status' => $status,
            ];
            $lines = [];

            if ($types !== []) {
                $read = ['rules' => $this->entitlements->fromVersion($version), 'closings' => $closings[$id] ?? [], 'types' => $types];
                foreach ($this->accrual->yearEnd($employee, $period, array_keys($types), $read) as $planned) {
                    $lines[(int) $planned['type']->id] = $line($planned['type'], $planned['closing'], $planned['carry'], $planned['lapse'], 'pending');
                }
            }
            foreach ($posted[$id] ?? [] as $typeId => $figures) {
                if (isset($byTypeId[$typeId])) {
                    $lines[$typeId] = $line($byTypeId[$typeId], $figures['carry'] + $figures['lapse'], $figures['carry'], $figures['lapse'], 'posted');
                }
            }
            foreach ($closings[$id] ?? [] as $typeId => $closing) {
                if ($closing > 0 && ! isset($lines[$typeId]) && isset($byTypeId[$typeId])) {
                    $lines[$typeId] = $line($byTypeId[$typeId], $closing, 0.0, 0.0, 'not_in_policy');
                }
            }
            uksort($lines, fn (int $a, int $b) => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));

            $out[$id] = [
                'policy_id' => $version?->policy_id !== null ? (int) $version->policy_id : null,
                'policy' => $version?->policy_id !== null ? ($policies[$version->policy_id] ?? null) : null,
                'lines' => array_values($lines),
            ];
        }

        return $out;
    }

    /**
     * What the year close posted, per employee and type: the days carried into the next year and the days lapsed.
     *
     * @param  list<int>  $employeeIds
     * @return array<int, array<int, array{carry: float, lapse: float}>>
     */
    private function postedFigures(array $employeeIds, int $period): array
    {
        [$carryKey] = self::keys($period);
        $out = [];
        foreach (array_chunk($employeeIds, 500) as $chunk) {
            LeaveLedgerEntry::query()->whereIn('employee_id', $chunk)->whereIn('accrual_key', self::keys($period))
                ->get(['id', 'employee_id', 'leave_type_id', 'accrual_key', 'days'])
                ->each(function (LeaveLedgerEntry $entry) use ($carryKey, &$out) {
                    $figures = $out[(int) $entry->employee_id][(int) $entry->leave_type_id] ?? ['carry' => 0.0, 'lapse' => 0.0];
                    if ($entry->accrual_key === $carryKey) {
                        $figures['carry'] += (float) $entry->days;
                    } else {
                        $figures['lapse'] -= (float) $entry->days;
                    }
                    $out[(int) $entry->employee_id][(int) $entry->leave_type_id] = $figures;
                });
        }

        return $out;
    }

    // --- Posting ---------------------------------------------------------------------------------------------------------

    /** Posting waits until the leave year has ended (leave taken later in the year would otherwise be carried forward too). */
    public function assertCanPost(int $period): void
    {
        if (! $this->yearEnded($period)) {
            throw new RuntimeException('The '.$this->name($period).' leave year ends on '.$this->endsOn($period)->format('j M Y').': carry forward once it has ended. The preview shows the balances so far.');
        }
    }

    /**
     * Post the year close for these employees and leave types — LeaveAccrual::closeYear for each, idempotent by its
     * ledger keys, audited as the year close is — in one audit operation (or inside the caller's, e.g. a bulk run's).
     * Each employee on their own: refused when outside the access scope or no longer employed.
     *
     * @param  iterable<Employee>  $employees
     * @param  list<string>|null  $codes  null = every type a leave policy carries
     * @return array{written: int, done: list<array{employee_id: int, label: string, message: string}>, skipped: list<array{employee_id: int, label: string, message: string}>, refused: list<array{employee_id: int, label: string, reason: string}>}
     */
    public function post(iterable $employees, int $period, ?array $codes, User $actor): array
    {
        $this->assertCanPost($period);
        $result = ['written' => 0, 'done' => [], 'skipped' => [], 'refused' => []];

        $run = function () use ($employees, $period, $codes, $actor, &$result) {
            foreach ($employees as $employee) {
                $label = self::employeeLabel($employee);
                try {
                    [$written, $message] = $this->postFor($employee, $period, $codes, $actor);
                } catch (RuntimeException $e) {
                    $result['refused'][] = ['employee_id' => (int) $employee->id, 'label' => $label, 'reason' => $e->getMessage()];

                    continue;
                }
                $result[$written > 0 ? 'done' : 'skipped'][] = ['employee_id' => (int) $employee->id, 'label' => $label, 'message' => $message];
                $result['written'] += $written;
            }

            return ['succeeded' => $result['written'], 'failed' => count($result['refused'])];
        };

        if (Context::get('audit.operation_id') !== null) {
            $run();
        } else {
            $this->audit->operation('leave', 'Carry forward leaves '.$this->name($period), $run, entityType: LeaveLedgerEntry::class);
        }

        return $result;
    }

    /**
     * One employee's year close for the types (the bulk run's step).
     *
     * @param  list<string>|null  $codes
     * @return array{0: int, 1: string} entries written and the outcome line
     *
     * @throws RuntimeException when refused
     */
    public function postFor(Employee $employee, int $period, ?array $codes, User $actor): array
    {
        $this->assertCanPost($period);
        if (! $this->scopes->allows($actor, $employee)) {
            throw new RuntimeException('Outside your access scope.');
        }
        $state = $employee->lifecycle_state;
        if (! $state instanceof LifecycleState || ! $state->isEmployed()) {
            throw new RuntimeException('Not employed: the year close takes employed people only.');
        }
        $employee->loadMissing('person');

        $codes = array_keys($codes === null ? $this->types($period) : array_intersect_key($this->types($period), array_flip(array_map('strval', $codes))));
        $lines = $this->preview([$employee], $period, $codes)[(int) $employee->id]['lines'] ?? [];
        $pending = array_values(array_filter($lines, fn (array $line) => $line['status'] === 'pending'));

        $written = $pending === [] ? 0 : $this->accrual->closeYear($employee, $period, $codes);
        if ($written > 0) {
            return [$written, implode('; ', array_map(fn (array $line) => $line['code'].': '.self::days($line['carry']).' carried forward, '.self::days($line['lapse']).' lapsed', $pending))];
        }

        return [0, collect($lines)->contains(fn (array $line) => $line['status'] === 'posted') ? 'Already carried forward: nothing new posted.' : 'Nothing to carry forward or lapse.'];
    }

    public static function employeeLabel(Employee $employee): string
    {
        return trim($employee->employee_code.' · '.($employee->loadMissing('person')->person?->display_name ?? ''), ' ·');
    }

    // --- The HR-desk reminder --------------------------------------------------------------------------------------------

    /**
     * The time office desk's reminder (matrix 24 §24.1 #22): from `leave.carry_forward_alert_days` days before a leave
     * year ends until the year is closed — no employee in the viewer's access scope still to carry forward. 0 (the
     * default) = no reminder.
     *
     * @return array{period: int, name: string, ended: bool, ends_on: string, days_left: int, pending: int}|null
     */
    public function alert(?CarbonInterface $today = null): ?array
    {
        $days = (int) $this->settings->get('leave.carry_forward_alert_days', 0);
        if ($days <= 0) {
            return null;
        }
        $today = Carbon::parse($today ?? now())->startOfDay();
        $current = $this->years->periodFor($today);

        foreach ([$current - 1, $current] as $period) {
            $ends = $this->endsOn($period);
            $left = (int) $today->diffInDays($ends, false);
            if ($period === $current && $left > $days) {
                continue;
            }
            $pending = $this->pendingCount($period);
            if ($pending > 0) {
                return ['period' => $period, 'name' => $this->name($period), 'ended' => $left < 0, 'ends_on' => $ends->toDateString(), 'days_left' => max(0, $left), 'pending' => $pending];
            }
        }

        return null;
    }

    // --- Download --------------------------------------------------------------------------------------------------------

    /**
     * The preview as CSV (one row per employee and leave type), audited as an export.
     *
     * @param  iterable<Employee>  $employees
     * @param  list<string>|null  $codes
     */
    public function csv(User $actor, iterable $employees, int $period, ?array $codes = null): string
    {
        $preview = $this->preview($employees, $period, $codes);
        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['Employee code', 'Employee name', 'Policy name', 'Leave year', 'Leave type', 'No. of total leave', 'No. of leaves to be lapsed', 'No. of leaves to be carried forward', 'Status']);
        $rows = 0;
        foreach ($employees as $employee) {
            $row = $preview[(int) $employee->id] ?? null;
            foreach ($row['lines'] ?? [] as $line) {
                fputcsv($out, [self::cell($employee->employee_code), self::cell($employee->person?->display_name), self::cell($row['policy']), self::cell($this->name($period)),
                    self::cell($line['code'].' · '.$line['type']), self::days($line['closing']), self::days($line['lapse']), self::days($line['carry']), self::STATUSES[$line['status']] ?? $line['status']]);
                $rows++;
            }
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        $this->audit->record(AuditAction::Export, 'leave', null, [], null, actor: $actor, entityLabel: 'Carry forward leaves ('.$this->name($period).')', metadata: [
            'export' => 'leave_carry_forward', 'rows' => $rows, 'period' => $period, 'leave_types' => $codes,
        ]);

        return $csv;
    }

    /** A text cell that cannot start a spreadsheet formula. */
    private static function cell(mixed $value): string
    {
        $text = (string) ($value ?? '');

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$text : $text;
    }
}
