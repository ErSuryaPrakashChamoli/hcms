<?php

namespace App\Domain\Analytics\Services;

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Grievance\Models\Grievance;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\People\Models\PersonSkill;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\Workflow\Enums\TaskStatus;
use App\Domain\Workflow\Models\WorkflowTask;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/** The KPI catalogue behind dashboards and the Workforce Command Centre (§56, §85). */
final class WorkforceMetrics
{
    /**
     * Phase 14: metrics that come from a protected domain need that domain's permission. A dashboard
     * viewer with only analytics.view sees these as "restricted", never the figure.
     */
    public const PERMISSIONS = [
        'people_cost' => ['payroll.view', 'compensation.analytics'], 'cost_per_head' => ['payroll.view', 'compensation.analytics'],
        'high_performers' => ['performance.analytics', 'performance.view'], 'learning_completion' => ['learning.analytics', 'learning.view'],
        'open_grievances' => ['grievance.view', 'grievance.manage'], 'open_tickets' => ['servicedesk.view', 'servicedesk.analytics'],
        'exits_in_progress' => ['exit.view'], 'pending_approvals' => ['workflow.view'],
    ];

    /** Phase 14: shares of a small population are suppressed (the PeopleOS small-group principle). */
    public const SUPPRESSED_BELOW_GROUP = ['women_share', 'high_performers', 'avg_tenure_months'];

    public function allowed(string $key, ?User $viewer): bool
    {
        if ($viewer === null || ! isset(self::PERMISSIONS[$key])) {
            return true;
        }
        foreach (self::PERMISSIONS[$key] as $permission) {
            if ($viewer->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{key: string, label: string, value: float|int|null, format: string, hint: ?string, restricted?: bool} */
    public function metric(string $key, ?User $viewer = null): array
    {
        return $this->within(fn () => $this->computeMetric($key, $viewer));
    }

    /** @return array{key: string, label: string, value: mixed, format: string, hint: ?string} */
    private function computeMetric(string $key, ?User $viewer): array
    {
        $label = config("peopleos.analytics.metrics.{$key}", $key);
        $viewer ??= auth()->user();
        if (! $this->allowed($key, $viewer)) {
            return ['key' => $key, 'label' => $label, 'value' => null, 'format' => 'number', 'hint' => 'Restricted: needs '.implode(' or ', self::PERMISSIONS[$key]), 'restricted' => true];
        }
        if (in_array($key, self::SUPPRESSED_BELOW_GROUP, true) && $this->headcount() < (int) config('peopleos.performance.analytics_min_group', 5)) {
            return ['key' => $key, 'label' => $label, 'value' => null, 'format' => 'number', 'hint' => 'Suppressed: population below the privacy threshold'];
        }
        [$value, $format, $hint] = match ($key) {
            'headcount' => [$this->headcount(), 'number', null],
            'joiners_30d' => [Employee::query()->whereDate('joining_date', '>=', now()->subDays(30))->whereDate('joining_date', '<=', now())->whereNotIn('lifecycle_state', ['pre_employee', 'preboarding'])->count(), 'number', null],
            'exits_30d' => [ExitCase::query()->where('status', 'completed')->whereDate('last_working_day', '>=', now()->subDays(30))->whereDate('last_working_day', '<=', now())->count(), 'number', null],
            'attrition_rate' => [$this->attritionRate(), 'percent', 'Exits in the last 12 months ÷ average headcount'],
            'absenteeism_rate' => [$this->absenteeismRate(), 'percent', 'Absent days ÷ scheduled working days, last 30 days'],
            'on_leave_today' => [LeaveRequest::query()->where('status', 'approved')->whereDate('from_date', '<=', now())->whereDate('to_date', '>=', now())->count(), 'number', null],
            'people_cost' => [$this->lastRun()?->total('employer_cost'), 'currency', $this->lastRun()?->period?->label()],
            'cost_per_head' => [($r = $this->lastRun()) && $r->total('employees') > 0 ? round($r->total('employer_cost') / $r->total('employees'), 2) : null, 'currency', $this->lastRun()?->period?->label()],
            'avg_tenure_months' => [$this->avgTenure(), 'number', null],
            'women_share' => [$this->womenShare(), 'percent', null],
            'high_performers' => [$this->highPerformers(), 'number', 'Final rating ≥ 4 in the latest closed cycle'],
            'learning_completion' => [$this->learningCompletion(), 'percent', 'Mandatory enrolments completed'],
            'open_tickets' => [Ticket::query()->whereIn('status', Ticket::OPEN)->count(), 'number', null],
            'open_grievances' => [Grievance::query()->whereIn('status', Grievance::OPEN)->count(), 'number', null],
            'exits_in_progress' => [ExitCase::query()->whereIn('status', ExitCase::OPEN)->count(), 'number', null],
            'pending_approvals' => [WorkflowTask::query()->where('status', TaskStatus::Pending)->count(), 'number', null],
            default => [null, 'number', null],
        };

        return compact('key', 'label', 'value', 'format', 'hint');
    }

    /** @return array<string, array{key: string, label: string, value: mixed, format: string, hint: ?string}> */
    public function all(array $keys, ?User $viewer = null): array
    {
        return $this->within(function () use ($keys, $viewer) {
            $out = [];
            foreach ($keys as $key) {
                $out[$key] = $this->metric($key, $viewer);
            }

            return $out;
        });
    }

    /**
     * @var array<string, int>|null UX.18: headcounts per day, kept only for the length of one aggregate operation (a set
     *                              of metrics, a series, the attrition rate); calls made one after another always count afresh.
     */
    private ?array $headcounts = null;

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function within(callable $operation): mixed
    {
        $outermost = $this->headcounts === null;
        if ($outermost) {
            $this->headcounts = [];
        }
        try {
            return $operation();
        } finally {
            if ($outermost) {
                $this->headcounts = null;
            }
        }
    }

    public function headcount(CarbonInterface|string|null $on = null): int
    {
        $day = Carbon::parse($on ?? now())->toDateString();
        if ($this->headcounts !== null && isset($this->headcounts[$day])) {
            return $this->headcounts[$day];
        }
        $count = Employee::query()
            ->whereNotIn('lifecycle_state', ['pre_employee', 'preboarding'])
            ->where(fn ($q) => $q->whereNull('joining_date')->orWhereDate('joining_date', '<=', $day))
            ->where(fn ($q) => $q->whereNull('exit_date')->orWhereDate('exit_date', '>=', $day)->orWhere(fn ($s) => $s->whereDate('exit_date', '<', $day)->whereNotIn('lifecycle_state', ['exited', 'alumni'])))
            ->count();
        if ($this->headcounts !== null) {
            $this->headcounts[$day] = $count;
        }

        return $count;
    }

    /**
     * UX.18: the headcount on several days in one aggregate query (one conditional sum per day, the same predicates
     * and scopes as headcount()); a twelve-month trend was twelve full counts.
     *
     * @param  list<string>  $days  Y-m-d
     * @return array<string, int>
     */
    public function headcounts(array $days): array
    {
        return $this->within(fn () => $this->countHeadcounts($days));
    }

    /**
     * @param  list<string>  $days
     * @return array<string, int>
     */
    private function countHeadcounts(array $days): array
    {
        $missing = array_values(array_unique(array_diff($days, array_keys($this->headcounts ?? []))));
        if ($missing !== []) {
            $columns = [];
            $bindings = [];
            foreach ($missing as $i => $day) {
                $columns[] = "SUM(CASE WHEN (joining_date IS NULL OR DATE(joining_date) <= ?) AND (exit_date IS NULL OR DATE(exit_date) >= ? OR (DATE(exit_date) < ? AND lifecycle_state NOT IN ('exited', 'alumni'))) THEN 1 ELSE 0 END) AS h{$i}";
                array_push($bindings, $day, $day, $day);
            }
            $row = (array) Employee::query()->whereNotIn('lifecycle_state', ['pre_employee', 'preboarding'])
                ->selectRaw(implode(', ', $columns), $bindings)->toBase()->first();
            foreach ($missing as $i => $day) {
                $this->headcounts[$day] = (int) ($row["h{$i}"] ?? 0);
            }
        }

        return array_intersect_key($this->headcounts, array_flip($days));
    }

    /** @return array{labels: array<int, string>, series: array<string, array<int, float>>} */
    public function series(string $key, int $months = 12): array
    {
        return $this->within(fn () => $this->computeSeries($key, $months));
    }

    /** @return array{labels: array<int, string>, series: array<string, array<int, float>>} */
    private function computeSeries(string $key, int $months): array
    {
        $labels = [];
        $values = [];
        if ($key === 'headcount') {
            // UX.18: every month end in one query.
            $this->countHeadcounts(array_map(fn (int $i) => now()->subMonthsNoOverflow($i)->startOfMonth()->endOfMonth()->toDateString(), range($months - 1, 0)));
        }
        for ($i = $months - 1; $i >= 0; $i--) {
            $month = now()->subMonthsNoOverflow($i)->startOfMonth();
            $labels[] = $month->format('M y');
            $values[] = (float) match ($key) {
                'headcount' => $this->headcount($month->copy()->endOfMonth()),
                'joiners' => Employee::query()->whereDate('joining_date', '>=', $month)->whereDate('joining_date', '<=', $month->copy()->endOfMonth())->whereNotIn('lifecycle_state', ['pre_employee', 'preboarding'])->count(),
                'exits' => ExitCase::query()->where('status', 'completed')->whereDate('last_working_day', '>=', $month)->whereDate('last_working_day', '<=', $month->copy()->endOfMonth())->count(),
                'people_cost' => PayrollRun::query()->whereIn('status', ['finalized', 'paid'])->whereHas('period', fn ($q) => $q->where('year', $month->year)->where('month', $month->month))->get()->sum(fn ($r) => $r->total('employer_cost')),
                'absenteeism' => $this->absenteeismRate($month, $month->copy()->endOfMonth()) ?? 0,
                default => 0,
            };
        }

        return ['labels' => $labels, 'series' => [config("peopleos.analytics.metrics.{$key}", ucfirst($key)) => $values]];
    }

    public function attritionRate(): ?float
    {
        return $this->within(fn () => $this->computeAttritionRate());
    }

    private function computeAttritionRate(): ?float
    {
        $exits = ExitCase::query()->where('status', 'completed')->whereDate('last_working_day', '>=', now()->subMonths(12))->count();
        $this->countHeadcounts([now()->subMonths(12)->toDateString(), now()->toDateString()]);
        $avg = ($this->headcount(now()->subMonths(12)) + $this->headcount()) / 2;

        return $avg > 0 ? round($exits / $avg * 100, 1) : null;
    }

    public function absenteeismRate(?CarbonInterface $from = null, ?CarbonInterface $to = null): ?float
    {
        $from = $from ?? now()->subDays(30);
        $to = $to ?? now();
        $records = AttendanceRecord::query()->whereBetween('date', [$from->toDateString(), $to->toDateString()])->whereNotIn('status', ['weekly_off', 'holiday', 'not_processed'])->count();
        if ($records === 0) {
            return null;
        }
        $absent = AttendanceRecord::query()->whereBetween('date', [$from->toDateString(), $to->toDateString()])->where('status', 'absent')->count();

        return round($absent / $records * 100, 1);
    }

    public function avgTenure(): ?float
    {
        // Raw dates (the query's scopes still apply) and Carbon's month difference once per distinct joining date:
        // bounded by the days in the company's history, not by headcount. Hydrating a model and diffing per
        // employee took seconds at 10,000 people.
        $days = Employee::query()->employed()->whereNotNull('joining_date')->toBase()->pluck('joining_date')->countBy(fn ($d) => substr((string) $d, 0, 10));
        if ($days->isEmpty()) {
            return null;
        }
        $now = now();
        $months = 0.0;
        foreach ($days as $day => $people) {
            $months += Carbon::parse($day)->diffInMonths($now) * $people;
        }

        return round($months / $days->sum(), 1);
    }

    public function womenShare(): ?float
    {
        $total = Employee::query()->employed()->count();
        if ($total === 0) {
            return null;
        }
        $women = Employee::query()->employed()->whereHas('person', fn ($q) => $q->where('gender', 'female'))->count();

        return round($women / $total * 100, 1);
    }

    public function highPerformers(): ?int
    {
        $cycle = PerformanceCycle::query()->whereIn('status', ['closed', 'active'])->orderByDesc('period_end')->first();

        return $cycle ? Appraisal::query()->where('performance_cycle_id', $cycle->id)->whereNotNull('final_rating')->where('final_rating', '>=', 4)->count() : null;
    }

    public function learningCompletion(): ?float
    {
        $mandatory = LearningEnrolment::query()->where('is_mandatory', true)->whereNotIn('status', ['withdrawn']);
        $total = (clone $mandatory)->count();

        return $total > 0 ? round((clone $mandatory)->where('status', 'completed')->count() / $total * 100, 1) : null;
    }

    /** @return array<int, array{skill: string, experts: int, holders: int}> */
    public function criticalSkills(int $limit = 8): array
    {
        return PersonSkill::query()->with('skill')->get()->groupBy('skill_id')->map(fn ($group) => [
            'skill' => $group->first()->skill?->name ?? '—',
            'holders' => $group->count(),
            'experts' => $group->whereIn('proficiency', ['advanced', 'expert'])->count(),
        ])->sortBy([['experts', 'asc'], ['holders', 'desc']])->take($limit)->values()->all();
    }

    private function lastRun(): ?PayrollRun
    {
        return PayrollRun::query()->with('period')->whereIn('status', ['finalized', 'paid'])->orderByDesc('finalized_at')->first();
    }
}
