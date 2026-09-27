<?php

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Models\Dashboard;
use App\Domain\Analytics\Models\Report;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\NeedsAttention;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Collection;
use RuntimeException;

/** Resolves a dashboard's widgets into renderable data for one viewer (§85). */
final class Dashboards
{
    public function __construct(private readonly WorkforceMetrics $metrics, private readonly ReportRunner $runner, private readonly NeedsAttention $attention) {}

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

        return collect($dashboard->widgets ?? [])->map(fn (array $w) => $this->widget($w, $user))->values()->all();
    }

    /** @param  array<string, mixed>  $w */
    public function widget(array $w, User $user): array
    {
        $base = ['type' => $w['type'] ?? 'kpi', 'title' => $w['title'] ?? null, 'size' => (int) ($w['size'] ?? 1), 'error' => null];

        try {
            return $base + match ($base['type']) {
                'kpi' => ['metric' => $this->metrics->metric($w['metric'] ?? 'headcount')],
                'trend' => ['chart' => $this->metrics->series($w['metric'] ?? 'headcount', (int) ($w['months'] ?? 12))],
                'chart', 'table', 'leaderboard' => $this->reportWidget($w, $user, $base['type']),
                'alerts' => ['items' => $this->alerts($user)],
                default => [],
            };
        } catch (RuntimeException $e) {
            return array_merge($base, ['error' => $e->getMessage()]);
        }
    }

    private function reportWidget(array $w, User $user, string $type): array
    {
        $report = Report::query()->find($w['report_id'] ?? 0);
        if ($report === null) {
            throw new RuntimeException('The report behind this widget no longer exists.');
        }
        if (! ($report->is_shared || $report->owner_id === $user->id || $user->hasPermission('analytics.manage'))) {
            throw new RuntimeException('You cannot view this report.');
        }
        $limit = $type === 'leaderboard' ? (int) ($w['limit'] ?? 5) : (int) ($w['limit'] ?? 25);
        $result = $this->runner->run($report, $user, $limit);

        return ['report' => ['id' => $report->id, 'name' => $report->name], 'result' => $result, 'chart_type' => $report->def('visualization.type', 'bar')];
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
}
