<?php

namespace App\Domain\Experience\Services;

use App\Domain\Analytics\Services\WorkforceMetrics;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Succession\Models\CriticalPosition;
use App\Domain\Succession\Services\CriticalPositions;
use App\Domain\Workforce\Models\Position;
use App\Domain\Workforce\Models\WorkforcePlanVersion;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Throwable;

/**
 * UX.15 "Workforce pulse": the workforce told as a story instead of a wall of charts. A headline from real
 * figures (movement this month against last month), then movement, size, attendance, performance and
 * capability, planning and exceptions, each figure drillable.
 *
 * Aggregates follow WorkforceMetrics (the same queries, permissions and small-group suppression). A
 * drill-down lists people only for viewers who may see people (employee.view, organisation scope applies
 * through PeopleVisibility); anyone else gets counts by department, with groups below the privacy threshold
 * shown as "fewer than N". Critical positions need a succession permission. Nothing is estimated.
 */
final class WorkforcePulse
{
    public const MOVES = ['transfer', 'reassignment', 'demotion'];

    public function __construct(private readonly WorkforceMetrics $metrics, private readonly PeopleVisibility $people) {}

    /** @return array<string, mixed> */
    public function for(User $viewer): array
    {
        $now = now();
        $thisMonth = [$now->copy()->startOfMonth(), $now->copy()->endOfDay()];
        $lastMonth = [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()];
        $headcount = $this->metrics->headcount();
        $headcountLast = $this->metrics->headcount($lastMonth[1]);
        $count = fn (array $p) => [
            'moves' => $this->positions(self::MOVES, $p)->count(),
            'promotions' => $this->positions(['promotion'], $p)->count(),
            'joiners' => $this->joiners($p)->count(),
            'exits' => $this->exits($p)->count(),
        ];
        $mNow = $count($thisMonth);
        $mLast = $count($lastMonth);
        $rate = fn (array $m, int $hc) => $hc > 0 ? round(array_sum($m) / $hc * 100, 1) : null;
        $rateNow = $rate($mNow, $headcount);
        $rateLast = $rate($mLast, $headcountLast);

        return [
            'headline' => $this->headline($mNow, $rateNow, $rateLast),
            'movement' => ['now' => $mNow, 'last' => $mLast, 'rate' => $rateNow, 'rate_last' => $rateLast, 'critical' => $this->safe(fn () => $this->criticalAffected($viewer), null)],
            'size' => ['headcount' => $headcount, 'last' => $headcountLast, 'series' => $this->safe(fn () => $this->metrics->series('headcount', 12), ['labels' => [], 'series' => []])],
            'metrics' => $this->metrics->all(['absenteeism_rate', 'on_leave_today', 'high_performers', 'learning_completion', 'attrition_rate', 'people_cost', 'open_grievances', 'avg_tenure_months'], $viewer),
            'critical_skills' => $this->safe(fn () => $this->metrics->criticalSkills(), []),
            'planning' => [
                'open_positions' => $viewer->hasPermission('workforce.view') || $viewer->hasPermission('workforce.manage') ? $this->safe(fn () => Position::query()->where('status', 'open')->count(), null) : null,
                'plans_in_review' => $viewer->hasPermission('workforce.approve') ? $this->safe(fn () => WorkforcePlanVersion::query()->whereIn('status', ['submitted', 'under_review'])->count(), null) : null,
            ],
            'names' => $viewer->hasPermission('employee.view'),
        ];
    }

    /**
     * The people (or, without people access, the departments) behind one figure.
     *
     * @return array{title: string, why: string, rows: list<array{person_id: ?int, label: string, detail: ?string, date: ?CarbonInterface}>, aggregated: bool}|null
     */
    public function drill(User $viewer, string $key): ?array
    {
        $month = [now()->startOfMonth(), now()->endOfDay()];
        [$title, $why, $items] = match ($key) {
            'moves' => ['Internal moves this month', 'Transfers, reassignments and demotions effective this month.', $this->positions(self::MOVES, $month)->with(['employee.person', 'department', 'designation'])->get()
                ->map(fn (EmployeePosition $p) => ['employee' => $p->employee, 'detail' => collect([$p->designation?->name, $p->department?->name])->filter()->implode(' · ') ?: ucfirst((string) $p->change_type), 'dept' => $p->department?->name, 'date' => $p->effective_from])],
            'promotions' => ['Promotions this month', 'Promotions effective this month.', $this->positions(['promotion'], $month)->with(['employee.person', 'department', 'designation'])->get()
                ->map(fn (EmployeePosition $p) => ['employee' => $p->employee, 'detail' => collect([$p->designation?->name, $p->department?->name])->filter()->implode(' · '), 'dept' => $p->department?->name, 'date' => $p->effective_from])],
            'joiners' => ['Joined this month', 'People whose joining date is this month.', $this->joiners($month)->with(['person', 'currentPosition.department'])->get()
                ->map(fn (Employee $e) => ['employee' => $e, 'detail' => $e->currentPosition?->department?->name, 'dept' => $e->currentPosition?->department?->name, 'date' => $e->joining_date])],
            'exits' => ['Left this month', 'Exits completed with a last working day this month.', $this->exits($month)->with(['employee.person', 'employee.currentPosition.department'])->get()
                ->map(fn (ExitCase $x) => ['employee' => $x->employee, 'detail' => $x->employee?->currentPosition?->department?->name, 'dept' => $x->employee?->currentPosition?->department?->name, 'date' => $x->last_working_day])],
            'on_leave' => ['On leave today', 'Approved leave covering today.', LeaveRequest::query()->with(['employee.person', 'employee.currentPosition.department', 'leaveType'])->where('status', 'approved')
                ->whereDate('from_date', '<=', now())->whereDate('to_date', '>=', now())->get()
                ->map(fn (LeaveRequest $r) => ['employee' => $r->employee, 'detail' => $r->employee?->currentPosition?->department?->name, 'dept' => $r->employee?->currentPosition?->department?->name, 'date' => $r->to_date])],
            default => [null, null, null],
        };
        if ($title === null) {
            return null;
        }
        $items = $items->filter(fn ($i) => $i['employee'] !== null)->values();

        if (! $viewer->hasPermission('employee.view')) {
            // Aggregates only: counts by department (never designation, which could single someone out), small groups suppressed.
            $min = (int) config('peopleos.workforce.analytics_min_group', 5);
            $rows = $items->groupBy(fn ($i) => $i['dept'] ?: 'No department')->sortByDesc(fn (Collection $g) => $g->count())->map(fn (Collection $g, string $dept) => [
                'person_id' => null, 'label' => $dept, 'detail' => $g->count() < $min ? 'fewer than '.$min : $g->count().' people', 'date' => null,
            ])->values()->all();

            return ['title' => $title, 'why' => $why.' Names need people access; small groups are not shown.', 'rows' => $rows, 'aggregated' => true];
        }
        $visible = $this->people->query($viewer)->whereKey($items->pluck('employee.id')->all())->pluck('employees.id')->map(fn ($id) => (int) $id)->all();

        return ['title' => $title, 'why' => $why, 'aggregated' => false, 'rows' => $items->filter(fn ($i) => in_array((int) $i['employee']->id, $visible, true))
            ->map(fn ($i) => ['person_id' => (int) $i['employee']->id, 'label' => (string) $i['employee']->display_name, 'detail' => $i['detail'], 'date' => $i['date']])->values()->all()];
    }

    /** @param list<string> $types */
    private function positions(array $types, array $period)
    {
        return EmployeePosition::query()->whereIn('change_type', $types)->whereDate('effective_from', '>=', $period[0])->whereDate('effective_from', '<=', $period[1]);
    }

    private function joiners(array $period)
    {
        return Employee::query()->whereDate('joining_date', '>=', $period[0])->whereDate('joining_date', '<=', $period[1])->whereNotIn('lifecycle_state', ['pre_employee', 'preboarding']);
    }

    private function exits(array $period)
    {
        return ExitCase::query()->where('status', 'completed')->whereDate('last_working_day', '>=', $period[0])->whereDate('last_working_day', '<=', $period[1]);
    }

    /** Active critical positions with an incumbent exit already initiated, or with no incumbent at all. */
    private function criticalAffected(User $viewer): ?int
    {
        if (! $viewer->hasPermission('succession.view') && ! $viewer->hasPermission('succession.read') && ! $viewer->hasPermission('succession.manage')) {
            return null;
        }
        $service = app(CriticalPositions::class);

        return CriticalPosition::query()->where('status', 'active')->get()
            ->filter(fn (CriticalPosition $p) => $service->upcomingIncumbentExit($p) !== null || $service->incumbents($p)->isEmpty())->count();
    }

    /** One sentence from the figures above; never a claim the figures do not support. */
    private function headline(array $m, ?float $rate, ?float $last): string
    {
        $parts = array_filter([
            $m['moves'] > 0 ? $m['moves'].' internal '.($m['moves'] === 1 ? 'move' : 'moves') : null,
            $m['promotions'] > 0 ? $m['promotions'].' '.($m['promotions'] === 1 ? 'promotion' : 'promotions') : null,
            $m['joiners'] > 0 ? $m['joiners'].' '.($m['joiners'] === 1 ? 'joiner' : 'joiners') : null,
            $m['exits'] > 0 ? $m['exits'].' '.($m['exits'] === 1 ? 'exit' : 'exits') : null,
        ]);
        if ($parts === []) {
            return 'No workforce movement recorded this month yet.';
        }
        $list = count($parts) > 1 ? implode(', ', array_slice($parts, 0, -1)).' and '.end($parts) : reset($parts);
        if ($rate === null) {
            return 'This month: '.$list.'.';
        }
        $trend = $last === null ? '' : ($rate > $last ? ', up from '.$last.'% last month' : ($rate < $last ? ', down from '.$last.'% last month' : ', the same as last month'));

        return 'Workforce movement is '.$rate.'% of headcount this month'.$trend.': '.$list.'.';
    }

    private function safe(callable $callback, mixed $fallback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            report($e);

            return $fallback;
        }
    }
}
