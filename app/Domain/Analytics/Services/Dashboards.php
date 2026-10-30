<?php

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Datasets\Dataset;
use App\Domain\Analytics\Models\Dashboard;
use App\Domain\Analytics\Models\Report;
use App\Domain\Employment\Models\Employee;
use App\Domain\Engagement\Models\SurveyVersion;
use App\Domain\Engagement\Services\EngagementAnalytics;
use App\Domain\Experience\Services\NeedsAttention;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Payroll\Contracts\PayrollSummaryReader;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Services\NineBox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Resolves a dashboard's widgets into renderable data for one viewer (§85).
 *
 * HROne parity CA-10 (CXO access): a dashboard renders as its viewer (their rights and access scope, whoever asks). A
 * dashboard that is a shared view renders inside the view's organisation slice (AccessScopes::withinSlice — a constraint
 * on top of the viewer's own access, never a grant); in a sliced view a figure the slice cannot narrow is not shown;
 * payroll figures come from finalized payroll through the payroll read contract, as of the view's refresh day; the CXO
 * widgets (payroll summary, 9-box, survey) show aggregates only, small groups suppressed.
 */
final class Dashboards
{
    public const NOT_IN_SLICE = 'Not available in a view limited to part of the organisation.';

    /** Presets whose views cover the whole organisation only (HROne: ROI and HR Ops; PeopleOS: the happiness index too). */
    public const ENTERPRISE_ONLY = ['hr_ops', 'happiness'];

    public function __construct(
        private readonly WorkforceMetrics $metrics,
        private readonly ReportRunner $runner,
        private readonly NeedsAttention $attention,
        private readonly PayrollSummaryReader $payroll,
    ) {}

    /** Dashboards the viewer may open, default first. */
    public function forUser(User $user): Collection
    {
        return Dashboard::query()->where('status', 'active')->orderByDesc('is_default')->orderBy('sort_order')->get()->filter(fn (Dashboard $d) => $d->isForUser($user))->values();
    }

    /** @return array<int, array<string, mixed>> */
    public function render(Dashboard $dashboard, User $user): array
    {
        if (! $dashboard->isForUser($user)) {
            throw new RuntimeException('This dashboard is not available to you.');
        }

        return $this->asViewer($user, function () use ($dashboard, $user) {
            $view = $dashboard->isView() ? $this->viewContext($dashboard) : null;
            $widgets = collect($dashboard->widgets ?? []);
            if ($view !== null && $view['problem'] !== null) {
                return $widgets->map(fn (array $w) => array_merge($this->base($w), ['error' => $view['problem']]))->values()->all();
            }
            $render = fn () => $widgets->map(fn (array $w) => $this->widget($w, $user, $view))->values()->all();

            return $view === null ? $render() : app(AccessScopes::class)->withinSlice($view['slice'], $render);
        });
    }

    /**
     * CA-10: how a shared view renders — its slice, the moment its payroll figures stand at, and a problem that stops it
     * (a malformed slice, a slice on a whole-organisation view).
     *
     * @return array{slice: array<string, list<int>>, sliced: bool, payroll_as_of: ?Carbon, payroll_problem: ?string, problem: ?string, preset: ?string}
     */
    public function viewContext(Dashboard $dashboard): array
    {
        $slice = $dashboard->slice();
        $preset = $dashboard->getAttributes()['preset'] ?? null;
        $problem = match (true) {
            $slice === null => 'This view\'s organisation filter is not valid; an analytics manager must correct it.',
            $slice !== [] && in_array($preset, self::ENTERPRISE_ONLY, true) => 'This view covers the whole organisation only; an analytics manager must remove its organisation filter.',
            default => null,
        };

        return [
            'slice' => $slice ?? [], 'sliced' => ($slice ?? []) !== [], 'preset' => $preset, 'problem' => $problem,
            'payroll_as_of' => $dashboard->payrollAsOf(),
            'payroll_problem' => $dashboard->hasValidRefreshDay() ? null : 'This view\'s payroll refresh day is not valid (1–28); an analytics manager must correct it.',
        ];
    }

    /**
     * @param  array<string, mixed>  $w
     * @param  array<string, mixed>|null  $view  CA-10: the shared view's context (viewContext()), null for a plain dashboard
     */
    public function widget(array $w, User $user, ?array $view = null): array
    {
        $base = $this->base($w);

        try {
            return $base + match ($base['type']) {
                'kpi' => ['metric' => $this->kpi((string) ($w['metric'] ?? 'headcount'), $user, $view)],
                'trend' => ['chart' => $this->trend((string) ($w['metric'] ?? 'headcount'), (int) ($w['months'] ?? 12), $user, $view)],
                'chart', 'table', 'leaderboard' => $this->reportWidget($w, $user, $base['type'], $view),
                'alerts' => ($view['sliced'] ?? false) ? throw new RuntimeException(self::NOT_IN_SLICE) : ['items' => $this->alerts($user)],
                'payroll' => ['payroll' => $this->payrollSummary($user, $view)],
                'nine_box' => ['nine_box' => $this->nineBox($user)],
                'survey' => ['survey' => $this->survey(($w['survey_kind'] ?? 'survey') === 'pulse' ? 'pulse' : 'survey', $user)],
                default => [],
            };
        } catch (RuntimeException $e) {
            return array_merge($base, ['error' => $e->getMessage()]);
        }
    }

    /** @param  array<string, mixed>  $w */
    private function base(array $w): array
    {
        return ['type' => $w['type'] ?? 'kpi', 'title' => $w['title'] ?? null, 'size' => (int) ($w['size'] ?? 1), 'error' => null];
    }

    /**
     * CA-10: a dashboard renders as its viewer — their permissions, their access scope (the AccessScope global scope reads
     * the signed-in user) — whoever asks for it; the signed-in user is restored afterwards.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function asViewer(User $user, callable $callback): mixed
    {
        $current = auth()->user();
        if ($current instanceof User && $current->is($user)) {
            return $callback();
        }
        auth()->setUser($user);
        try {
            return $callback();
        } finally {
            $current ? auth()->setUser($current) : auth()->forgetUser();
        }
    }

    /** @param  array<string, mixed>|null  $view */
    private function kpi(string $key, User $user, ?array $view): array
    {
        if ($view !== null) {
            if ($view['sliced'] && ! in_array($key, WorkforceMetrics::SLICEABLE, true)) {
                throw new RuntimeException(self::NOT_IN_SLICE);
            }
            if (in_array($key, ['people_cost', 'cost_per_head'], true)) {
                return $this->payrollMetric($key, $user, $view);
            }
        }

        return $this->metrics->metric($key, $user);
    }

    /** @param  array<string, mixed>|null  $view */
    private function trend(string $key, int $months, User $user, ?array $view): array
    {
        // CA-10: a trend of a protected figure needs that figure's right, as its KPI does.
        if (! $this->metrics->allowed($key, $user)) {
            throw new RuntimeException('Restricted: needs '.implode(' or ', WorkforceMetrics::PERMISSIONS[$key]).'.');
        }
        if ($view === null) {
            return $this->metrics->series($key, $months);
        }
        if ($view['sliced'] && ! in_array($key, WorkforceMetrics::SLICEABLE_SERIES, true)) {
            throw new RuntimeException(self::NOT_IN_SLICE);
        }
        if ($key !== 'people_cost') {
            return $this->metrics->series($key, $months);
        }
        if ($view['payroll_problem'] !== null) {
            throw new RuntimeException($view['payroll_problem']);
        }
        $months = max(1, min(36, $months));
        $figures = $this->payroll->byMonth(now()->startOfMonth()->subMonthsNoOverflow($months - 1), now()->startOfMonth(), $view['payroll_as_of']);
        $k = self::payrollMinGroup();
        $full = $user->hasPermission('payroll.view');
        $labels = [];
        $values = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $month = now()->startOfMonth()->subMonthsNoOverflow($i);
            $row = $figures[$month->format('Y-m')] ?? null;
            $labels[] = $month->format('M y');
            // A month paid to fewer people than the threshold is left out for viewers without payroll.view.
            $values[] = $row === null || (! $full && $row['employees'] < $k) ? 0.0 : (float) $row['employer_cost'];
        }

        return ['labels' => $labels, 'series' => [config('peopleos.analytics.metrics.people_cost', 'People cost') => $values]];
    }

    /**
     * CA-10: people cost and cost per head of a shared view: the latest payroll month finalized before the view's refresh
     * day, summed over the payroll entries the viewer may see in the slice; suppressed below the compensation threshold
     * for viewers without payroll.view.
     *
     * @param  array<string, mixed>  $view
     */
    private function payrollMetric(string $key, User $user, array $view): array
    {
        $label = config("peopleos.analytics.metrics.{$key}", $key);
        if (! $this->metrics->allowed($key, $user)) {
            return ['key' => $key, 'label' => $label, 'value' => null, 'format' => 'number', 'hint' => 'Restricted: needs '.implode(' or ', WorkforceMetrics::PERMISSIONS[$key]), 'restricted' => true];
        }
        if ($view['payroll_problem'] !== null) {
            throw new RuntimeException($view['payroll_problem']);
        }
        $month = $this->payroll->latestMonth($view['payroll_as_of']);
        if ($month === null) {
            return ['key' => $key, 'label' => $label, 'value' => null, 'format' => 'currency', 'hint' => 'No finalized payroll'.self::asOf($view['payroll_as_of'])];
        }
        if (! $user->hasPermission('payroll.view') && $month['employees'] < self::payrollMinGroup()) {
            return ['key' => $key, 'label' => $label, 'value' => null, 'format' => 'currency', 'hint' => 'Suppressed: fewer than '.self::payrollMinGroup().' people paid in this view'];
        }
        $value = $key === 'people_cost' ? $month['employer_cost'] : ($month['employees'] > 0 ? round($month['employer_cost'] / $month['employees'], 2) : null);

        return ['key' => $key, 'label' => $label, 'value' => $value, 'format' => 'currency', 'hint' => $month['period'].self::asOf($view['payroll_as_of'])];
    }

    /**
     * CA-10 payroll widget (HROne "Effective CTC = net paid + contributions + deductions …"): the latest finalized month's
     * gross, net paid, deductions and employer cost, and the people paid — the people_cost right, suppressed as above.
     *
     * @param  array<string, mixed>|null  $view
     * @return array<string, mixed>|null
     */
    private function payrollSummary(User $user, ?array $view): ?array
    {
        if (! $this->metrics->allowed('people_cost', $user)) {
            throw new RuntimeException('Restricted: needs '.implode(' or ', WorkforceMetrics::PERMISSIONS['people_cost']).'.');
        }
        if (($view['payroll_problem'] ?? null) !== null) {
            throw new RuntimeException($view['payroll_problem']);
        }
        $asOf = $view['payroll_as_of'] ?? null;
        $month = $this->payroll->latestMonth($asOf);
        if ($month === null) {
            return null;
        }
        $k = self::payrollMinGroup();
        if (! $user->hasPermission('payroll.view') && $month['employees'] < $k) {
            return ['period' => $month['period'], 'suppressed' => true, 'min_group' => $k, 'as_of' => $asOf?->toDateString()];
        }

        return ['period' => $month['period'], 'suppressed' => false, 'min_group' => $k, 'as_of' => $asOf?->toDateString(), 'employees' => $month['employees'],
            'gross' => $month['gross'], 'net_pay' => $month['net_pay'], 'total_deductions' => $month['total_deductions'], 'employer_cost' => $month['employer_cost']];
    }

    /**
     * @param  array<string, mixed>  $w
     * @param  array<string, mixed>|null  $view
     */
    private function reportWidget(array $w, User $user, string $type, ?array $view): array
    {
        $report = Report::query()->find($w['report_id'] ?? 0);
        if ($report === null) {
            throw new RuntimeException('The report behind this widget no longer exists.');
        }
        if (! ($report->is_shared || $report->owner_id === $user->id || $user->hasPermission('analytics.manage'))) {
            throw new RuntimeException('You cannot view this report.');
        }
        $asOf = null;
        if ($view !== null) {
            $dataset = app(DatasetRegistry::class)->get($report->dataset);
            if ($view['sliced'] && ! self::followsSlice($dataset)) {
                throw new RuntimeException(self::NOT_IN_SLICE);
            }
            if ($dataset->payrollRunPath() !== null) {
                $asOf = $view['payroll_problem'] === null ? $view['payroll_as_of'] : throw new RuntimeException($view['payroll_problem']);
            }
        }
        $limit = $type === 'leaderboard' ? (int) ($w['limit'] ?? 5) : (int) ($w['limit'] ?? 25);
        $result = $this->runner->run($report, $user, $limit, false, $asOf);
        if (! empty($w['suppress'])) {
            $result = self::suppressSmallGroups($result);
        }

        return ['report' => ['id' => $report->id, 'name' => $report->name], 'result' => $result, 'chart_type' => $report->def('visualization.type', 'bar'), 'payroll_as_of' => $asOf?->toDateString()];
    }

    /**
     * CA-10: whether a slice narrows a dataset — its rows are employees, or employee-linked records under the access scope
     * (the AccessScope global scope, employee path). Organisation units, multi-dimension records, users, and anonymous or
     * organisation-wide aggregates (the happiness index) are not narrowed, so they are not shown in a sliced view.
     */
    public static function followsSlice(Dataset $dataset): bool
    {
        $model = $dataset->query()->getModel();
        if (! array_key_exists(AccessScope::class, $model->getGlobalScopes())) {
            return false;
        }
        if ($model instanceof Employee) {
            return true;
        }

        return ! method_exists($model, 'applyAccessScope') && ! property_exists($model, 'accessScopeDimension');
    }

    /**
     * CA-10: small-group suppression for a grouped count widget (a preset marks it `suppress`): groups below
     * peopleos.analytics.min_group are folded into one "fewer than k" row without figures, complementary suppression keeps
     * that remainder at 0 or at least k, and below k in all nothing is shown.
     */
    public static function suppressSmallGroups(ReportResult $result): ReportResult
    {
        $k = max(1, (int) config('peopleos.analytics.min_group', 5));
        if (! $result->grouped || ! array_key_exists('count', $result->columns)) {
            throw new RuntimeException('This widget shows only grouped counts.');
        }
        $counts = array_map(fn (array $row) => (int) ($row['count'] ?? 0), $result->rows);
        $total = array_sum($counts);
        if ($total < $k) {
            throw new RuntimeException("Suppressed: fewer than {$k} in this view.");
        }
        $shown = array_filter($counts, fn (int $n) => $n >= $k);
        asort($shown);
        while ($shown !== []) {
            $remainder = $total - array_sum($shown);
            if ($remainder === 0 || $remainder >= $k) {
                break;
            }
            array_shift($shown);
        }
        $keep = array_keys($shown);
        sort($keep);
        if (count($keep) === count($result->rows)) {
            return $result;
        }
        $groupBy = (string) array_key_first($result->columns);
        $rows = array_values(array_map(fn (int $i) => $result->rows[$i], $keep));
        $rows[] = array_merge(array_map(fn () => null, $result->columns), [$groupBy => "Fewer than {$k} each (".(count($result->rows) - count($keep)).' groups)']);
        $charted = array_values(array_filter($keep, fn (int $i) => isset($result->chart['labels'][$i])));
        $chart = ['labels' => array_map(fn (int $i) => $result->chart['labels'][$i], $charted), 'series' => []];
        foreach ($result->chart['series'] as $name => $values) {
            $chart['series'][$name] = array_map(fn (int $i) => $values[$i] ?? 0.0, $charted);
        }

        return new ReportResult($result->columns, $rows, $result->total, true, $chart, null, $result->truncated);
    }

    /**
     * CA-10 9-box widget: counts per box of the latest active or closed cycle, as NineBox places them for the viewer (their
     * view mode and access scope; the slice applies through the appraisals' scope). Never names; a box below
     * performance.analytics_min_group is hidden, with complementary suppression, and nothing below it in all.
     *
     * @return array<string, mixed>|null
     */
    private function nineBox(User $user): ?array
    {
        if (NineBox::viewMode($user) === null) {
            throw new RuntimeException('Restricted: needs performance.view, performance.calibrate, performance.team or performance.analytics.');
        }
        $cycle = PerformanceCycle::query()->whereIn('status', ['active', 'closed'])->orderByDesc('period_end')->first();
        if ($cycle === null) {
            return null;
        }
        $grid = app(NineBox::class)->grid($cycle, $user);
        $k = NineBox::minGroup();
        $counts = $grid['counts'];
        $placed = $grid['mode'] === 'counts' ? ($grid['suppressed'] ? 0 : (int) $grid['placed']) : array_sum(array_map('intval', $counts));
        $suppressed = $placed < $k;
        $shown = $suppressed ? [] : array_filter($counts, fn ($n) => $n !== null && ($n === 0 || $n >= $k));
        $nonZero = array_filter($shown, fn ($n) => $n > 0);
        asort($nonZero);
        while ($nonZero !== []) {
            $remainder = $placed - array_sum($nonZero);
            if ($remainder === 0 || $remainder >= $k) {
                break;
            }
            unset($shown[array_key_first($nonZero)]);
            array_shift($nonZero);
        }
        $boxes = [];
        foreach ((array) config('peopleos.performance.nine_box.boxes', []) as $box => $label) {
            $boxes[$box] = ['label' => $label, 'count' => array_key_exists($box, $shown) ? (int) $shown[$box] : null];
        }

        return ['cycle' => $cycle->name, 'boxes' => $boxes, 'placed' => $suppressed ? null : $placed, 'suppressed' => $suppressed, 'min_group' => $k];
    }

    /**
     * CA-10 survey / pulse widget: the latest released version of a survey of that kind whose results the viewer may read
     * as HR (EngagementAnalytics::access — engagement.analytics and the whole population inside their access, and so inside
     * the view's slice: results are never filtered ad hoc), under its own suppression: respondents and per-question
     * averages only, nothing below the threshold, no comments.
     *
     * @return array<string, mixed>|null
     */
    private function survey(string $kind, User $user): ?array
    {
        if (! $user->hasPermission('engagement.analytics')) {
            throw new RuntimeException('Restricted: needs engagement.analytics.');
        }
        $analytics = app(EngagementAnalytics::class);
        $versions = SurveyVersion::query()->with('survey')->whereIn('status', ['closed', 'archived'])->whereNotNull('closed_at')
            ->whereHas('survey', fn ($survey) => $kind === 'pulse' ? $survey->where('survey_type', 'pulse') : $survey->where(fn ($q) => $q->whereNull('survey_type')->orWhere('survey_type', '!=', 'pulse')))
            ->orderByDesc('closed_at')->orderByDesc('id')->limit(20)->get();
        foreach ($versions as $version) {
            if ($analytics->access($version, $user) !== 'hr') {
                continue;
            }
            $results = $analytics->results($version, $user);
            $questions = [];
            foreach ($results['questions'] ?? [] as $question) {
                if (($question['overall']['average'] ?? null) !== null) {
                    $questions[] = ['prompt' => $question['prompt'], 'average' => $question['overall']['average'], 'answered' => $question['overall']['answered']];
                }
            }
            $rate = $results['suppressed'] ? null : ($analytics->participation($version, $user)['response_rate'] ?? null);

            return ['name' => $version->survey?->name, 'version' => $version->version, 'closed_on' => $version->closed_at?->toDateString(),
                'respondents' => $results['respondents'], 'response_rate' => $rate, 'suppressed' => (bool) $results['suppressed'],
                'message' => $results['message'] ?? null, 'questions' => $results['suppressed'] ? [] : $questions, 'k' => $results['k']];
        }

        return null;
    }

    private function alerts(User $user): array
    {
        $employee = Employee::query()->where('user_id', $user->id)->first();
        $items = collect();
        if ($employee) {
            $items = $items->merge($this->attention->forEmployee($employee, $user));
            if ($employee->directReports()->currentlyEffective()->exists()) {
                $items = $items->merge($this->attention->forManager($employee, $user));
            }
        }
        if ($user->hasPermission('employee.view')) {
            $items->push(['key' => 'exits', 'count' => $this->metrics->metric('exits_in_progress')['value'] ?? 0, 'title' => 'Exits in progress', 'detail' => '', 'severity' => 'warning', 'url' => null]);
            $items->push(['key' => 'tickets', 'count' => $this->metrics->metric('open_tickets')['value'] ?? 0, 'title' => 'Open service desk tickets', 'detail' => '', 'severity' => 'info', 'url' => null]);
        }

        return $items->filter(fn ($i) => ($i['count'] ?? 0) > 0)->unique('key')->values()->all();
    }

    private static function payrollMinGroup(): int
    {
        return max(1, (int) config('peopleos.compensation.analytics_min_group', 5));
    }

    private static function asOf(?Carbon $asOf): string
    {
        return $asOf === null ? '' : ' · as of '.$asOf->toFormattedDateString();
    }
}
