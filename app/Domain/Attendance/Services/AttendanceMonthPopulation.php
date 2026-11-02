<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Contracts\PendingLeaveReader;
use App\Domain\Attendance\Models\AttendanceRegularisation;
use App\Domain\Attendance\Models\TimeOfficeRequest;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Lifecycle\Enums\LifecycleState;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * HROne parity TX-3: who belongs to a company's attendance month, the narrowing the month desk offers ("Process a
 * subset"), and the requests still waiting for a decision on the month's days.
 *
 * The month's people: a position with the company for at least one day of the month, joined by its last day, not gone
 * before its first day, and past pre-boarding (employed, or exited during the month). Everything reads inside the
 * viewer's access scope unless asked otherwise (closing a month counts every pending request, seen or not).
 */
final class AttendanceMonthPopulation
{
    /** Filters the desk's processing and lists accept (ids of the current position, pasted codes, the attendance policy). */
    public const FILTERS = ['employee_ids', 'department_ids', 'location_ids', 'designation_ids', 'employment_type_ids', 'codes', 'exclude_codes', 'attendance_policy_id', 'punch_mode'];

    /** Request kinds the desk reminds about, in HROne's order. */
    public const PENDING_KINDS = [
        'regularisation' => 'Attendance regularisation',
        'leave' => 'Leave',
        'on_duty' => 'On duty',
        'restricted_holiday' => 'Restricted holiday',
        'short_leave' => 'Short leave',
        'other' => 'Shift, week-off, overtime and comp-off',
    ];

    public function __construct(private readonly PolicyResolver $policies, private readonly PendingLeaveReader $leave) {}

    /** @return list<string> lifecycle states counted in a month (employed, or exited during it) */
    public static function states(): array
    {
        return array_values(array_map(fn (LifecycleState $s) => $s->value, array_filter(LifecycleState::cases(), fn (LifecycleState $s) => $s->isEmployed() || $s === LifecycleState::Exited)));
    }

    public function query(int $companyId, CarbonInterface $start, CarbonInterface $end, bool $scoped = true): Builder
    {
        return Employee::query()
            ->when(! $scoped, fn (Builder $q) => $q->withoutGlobalScope(AccessScope::class))
            ->whereHas('positions', fn (Builder $q) => $q->withoutGlobalScope(AccessScope::class)->where('company_id', $companyId)
                ->where(fn (Builder $w) => $w->whereNull('effective_from')->orWhere('effective_from', '<=', $end->toDateString().' 23:59:59'))
                ->where(fn (Builder $w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', $start->toDateString())))
            ->where(fn (Builder $q) => $q->whereNull('joining_date')->orWhere('joining_date', '<=', $end->toDateString().' 23:59:59'))
            ->where(fn (Builder $q) => $q->whereNull('exit_date')->orWhere('exit_date', '>=', $start->toDateString()))
            ->whereIn('lifecycle_state', self::states());
    }

    /**
     * The month's people narrowed by the desk's filters (position fields on the month's last day, pasted codes, the
     * attendance policy and its way of counting check-ins on that day).
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Employee>
     */
    public function filtered(int $companyId, CarbonInterface $start, CarbonInterface $end, array $filters = [], bool $scoped = true): Collection
    {
        $ids = fn (string $key) => array_values(array_filter(array_map('intval', (array) ($filters[$key] ?? []))));
        $position = ['department_ids' => 'department_id', 'location_ids' => 'location_id', 'designation_ids' => 'designation_id', 'employment_type_ids' => 'employment_type_id'];
        $query = $this->query($companyId, $start, $end, $scoped)->with('person');
        if (($only = $ids('employee_ids')) !== []) {
            $query->whereKey($only);
        }
        foreach ($position as $key => $column) {
            if (($values = $ids($key)) !== []) {
                $query->whereHas('positions', fn (Builder $q) => $q->withoutGlobalScope(AccessScope::class)->effectiveOn($end)->whereIn($column, $values));
            }
        }
        if (($codes = self::codes($filters['codes'] ?? null)) !== []) {
            $query->whereIn('employee_code', $codes);
        }
        if (($leaveOut = self::codes($filters['exclude_codes'] ?? null)) !== []) {
            $query->whereNotIn('employee_code', $leaveOut);
        }
        $employees = $query->orderBy('employee_code')->get();

        $policy = $filters['attendance_policy_id'] ?? null;
        $mode = $filters['punch_mode'] ?? null;
        if (filled($policy) || filled($mode)) {
            $employees = $employees->filter(function (Employee $employee) use ($policy, $mode, $end) {
                $version = $this->policies->resolve('attendance', $employee, $end);
                if (filled($policy) && ($policy === 'none' ? $version !== null : (int) $version?->policy_id !== (int) $policy)) {
                    return false;
                }

                return ! filled($mode) || (string) ($version?->setting('punch_mode') ?? 'double') === (string) $mode;
            })->values();
        }

        return $employees;
    }

    /**
     * Requests still waiting for a decision on days of the window, by kind (new requests and cancellations of approved ones).
     *
     * @param  list<int>  $employeeIds
     * @return array<string, list<array{kind: string, employee_id: int, dates: string, status: string, what: string}>>
     */
    public function pending(array $employeeIds, CarbonInterface $start, CarbonInterface $end, bool $scoped = true): array
    {
        $out = array_fill_keys(array_keys(self::PENDING_KINDS), []);
        if ($employeeIds === []) {
            return $out;
        }
        $from = $start->toDateString();
        $to = $end->toDateString();

        $regularisations = AttendanceRegularisation::query()->when(! $scoped, fn (Builder $q) => $q->withoutGlobalScope(AccessScope::class))
            ->whereIn('employee_id', $employeeIds)->where('status', 'pending')->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->orderBy('date')->get(['id', 'employee_id', 'date', 'type', 'status']);
        foreach ($regularisations as $r) {
            $out['regularisation'][] = ['kind' => 'regularisation', 'employee_id' => (int) $r->employee_id, 'dates' => $r->date->toDateString(), 'status' => 'Pending', 'what' => 'Regularisation'];
        }

        $requests = TimeOfficeRequest::query()->when(! $scoped, fn (Builder $q) => $q->withoutGlobalScope(AccessScope::class))
            ->whereIn('employee_id', $employeeIds)->whereIn('status', ['pending', 'cancel_requested'])
            ->whereDate('from_date', '<=', $to)->whereDate('to_date', '>=', $from)->orderBy('from_date')->get(['id', 'employee_id', 'type', 'from_date', 'to_date', 'status']);
        foreach ($requests as $r) {
            $kind = array_key_exists($r->type, self::PENDING_KINDS) ? $r->type : 'other';
            $out[$kind][] = ['kind' => $kind, 'employee_id' => (int) $r->employee_id, 'dates' => self::span($r->from_date->toDateString(), $r->to_date->toDateString()),
                'status' => TimeOfficeRequest::STATUSES[$r->status] ?? $r->status, 'what' => TimeOfficeRequest::TYPES[$r->type] ?? $r->type];
        }

        foreach ($this->leave->pendingBetween($employeeIds, $start, $end, $scoped) as $r) {
            $out['leave'][] = ['kind' => 'leave', 'employee_id' => (int) $r['employee_id'], 'dates' => self::span($r['from'], $r['to']), 'status' => $r['status'], 'what' => $r['label']];
        }

        return $out;
    }

    /** @return list<string> codes pasted with commas, semicolons, spaces or new lines */
    public static function codes(mixed $value): array
    {
        if (is_array($value)) {
            $value = implode(',', $value);
        }

        return array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) $value) ?: []), fn (string $c) => $c !== '')));
    }

    private static function span(string $from, string $to): string
    {
        return $from === $to ? $from : $from.' – '.$to;
    }
}
