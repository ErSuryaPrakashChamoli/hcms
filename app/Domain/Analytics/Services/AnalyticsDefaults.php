<?php

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Models\Dashboard;
use App\Domain\Analytics\Models\Report;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

/** Starter shared reports and the default HR dashboard for every tenant; the expense dashboard (EX-G) and the CXO preset views (CA-10) on request. */
final class AnalyticsDefaults
{
    public const EXPENSE_DASHBOARD = 'expense-overview';

    public function seed(): void
    {
        $reports = [
            ['name' => 'Headcount by department', 'dataset' => 'employees', 'definition' => ['fields' => ['department', 'headcount'], 'filters' => [['field' => 'lifecycle_state', 'operator' => 'in', 'value' => 'joined,probation,confirmed,active,on_leave,notice_period']], 'group_by' => 'department', 'aggregations' => [['fn' => 'count']], 'sort' => ['field' => 'count', 'dir' => 'desc'], 'visualization' => ['type' => 'bar']]],
            ['name' => 'Exits by month', 'dataset' => 'exits', 'definition' => ['fields' => ['month', 'count'], 'filters' => [['field' => 'status', 'operator' => 'equals', 'value' => 'completed']], 'group_by' => 'month', 'aggregations' => [['fn' => 'count']], 'sort' => ['field' => 'month', 'dir' => 'asc'], 'visualization' => ['type' => 'line']]],
            ['name' => 'Payroll cost by period', 'dataset' => 'payroll', 'definition' => ['fields' => ['period', 'employer_cost', 'net_pay'], 'group_by' => 'period', 'aggregations' => [['fn' => 'sum', 'field' => 'employer_cost'], ['fn' => 'sum', 'field' => 'net_pay'], ['fn' => 'count']], 'sort' => ['field' => 'period', 'dir' => 'asc'], 'visualization' => ['type' => 'bar']]],
            ['name' => 'Absence by department (last 30 days)', 'dataset' => 'attendance', 'definition' => ['fields' => ['department', 'is_absent'], 'filters' => [['field' => 'date', 'operator' => 'last_days', 'value' => '30']], 'group_by' => 'department', 'aggregations' => [['fn' => 'sum', 'field' => 'is_absent'], ['fn' => 'count']], 'visualization' => ['type' => 'bar']]],
            ['name' => 'Mandatory learning completion', 'dataset' => 'learning', 'definition' => ['fields' => ['course', 'is_completed', 'is_overdue'], 'filters' => [['field' => 'is_mandatory', 'operator' => 'equals', 'value' => '1']], 'group_by' => 'course', 'aggregations' => [['fn' => 'count'], ['fn' => 'sum', 'field' => 'is_completed'], ['fn' => 'sum', 'field' => 'is_overdue']], 'visualization' => ['type' => 'table']]],
            ['name' => 'Rating distribution (latest cycle)', 'dataset' => 'performance', 'definition' => ['fields' => ['final_label'], 'filters' => [['field' => 'final_label', 'operator' => 'not_empty']], 'group_by' => 'final_label', 'aggregations' => [['fn' => 'count']], 'visualization' => ['type' => 'pie']]],
        ];

        $ids = [];
        foreach ($reports as $row) {
            $ids[$row['name']] = Report::query()->firstOrCreate(['name' => $row['name'], 'dataset' => $row['dataset']], $row + ['is_shared' => true, 'status' => 'active'])->id;
        }

        Dashboard::query()->firstOrCreate(['slug' => 'hr-overview'], [
            'name' => 'HR overview', 'description' => 'Headcount, movement, cost and attention items.', 'is_default' => true, 'status' => 'active', 'widgets' => [
                ['type' => 'kpi', 'title' => 'Headcount', 'metric' => 'headcount', 'size' => 1],
                ['type' => 'kpi', 'title' => 'Attrition (12 mo)', 'metric' => 'attrition_rate', 'size' => 1],
                ['type' => 'kpi', 'title' => 'Absenteeism (30 d)', 'metric' => 'absenteeism_rate', 'size' => 1],
                ['type' => 'kpi', 'title' => 'People cost', 'metric' => 'people_cost', 'size' => 1],
                ['type' => 'trend', 'title' => 'Headcount trend', 'metric' => 'headcount', 'size' => 2],
                ['type' => 'chart', 'title' => 'Headcount by department', 'report_id' => $ids['Headcount by department'], 'size' => 2],
                ['type' => 'alerts', 'title' => 'Needs attention', 'size' => 2],
                ['type' => 'table', 'title' => 'Mandatory learning completion', 'report_id' => $ids['Mandatory learning completion'], 'size' => 2],
            ],
        ]);
    }

    /**
     * HROne setup parity EX-G (AN-07): the expense dashboard — expense KPIs and three shared reports from the report
     * library, for the roles that see expenses (expense.view) and analytics managers. Added by an analytics manager
     * (Dashboards → Add the expense dashboard), never at provisioning; adding it again changes nothing.
     */
    public function seedExpense(): Dashboard
    {
        $library = app(ReportLibrary::class);
        $ids = [];
        foreach (['expense_by_category_this_year', 'outstanding_expense_advances', 'expense_payment_register'] as $key) {
            $template = $library->find($key);
            $ids[$key] = Report::query()->firstOrCreate(['name' => $template['name'], 'dataset' => $template['dataset']],
                ['description' => $template['description'], 'definition' => $template['definition'], 'is_shared' => true, 'status' => 'active'])->id;
        }
        $roles = Role::query()->whereHas('permissions', fn ($q) => $q->where('key', 'expense.view'))->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        return Dashboard::query()->firstOrCreate(['slug' => self::EXPENSE_DASHBOARD], [
            'name' => 'Expense overview', 'description' => 'Expense reports waiting, money owed to employees, advances outstanding, and where the money goes.',
            'is_default' => false, 'status' => 'active', 'sort_order' => 20, 'role_ids' => $roles, 'widgets' => [
                ['type' => 'kpi', 'title' => 'Reports waiting for approval', 'metric' => 'expense_reports_pending', 'size' => 1],
                ['type' => 'kpi', 'title' => 'To pay', 'metric' => 'expense_payable', 'size' => 1],
                ['type' => 'kpi', 'title' => 'Advances outstanding', 'metric' => 'expense_advances_outstanding', 'size' => 1],
                ['type' => 'kpi', 'title' => 'Advances past settle-by', 'metric' => 'expense_advances_overdue', 'size' => 1],
                ['type' => 'chart', 'title' => 'Expense by category (this financial year)', 'report_id' => $ids['expense_by_category_this_year'], 'size' => 2],
                ['type' => 'table', 'title' => 'Outstanding expense advances', 'report_id' => $ids['outstanding_expense_advances'], 'size' => 2],
                ['type' => 'table', 'title' => 'Expense payment register', 'report_id' => $ids['expense_payment_register'], 'size' => 4],
            ],
        ]);
    }

    /**
     * HROne parity CA-10 (Setup → Access rights → CXO access): the preset views an analytics manager can add, one per
     * HROne dashboard. `whole_organisation` views take no organisation filter (HROne: ROI, HR Ops; PeopleOS: the happiness
     * index, which covers the whole organisation only). Waiting for their data: ROI (AN-06 / AN-11); recruitment is N-A.
     *
     * @var array<string, array{label: string, description: string, whole_organisation?: bool}>
     */
    public const PRESETS = [
        'movement' => ['label' => 'Employee movement', 'description' => 'Headcount, joiners, exits and attrition, upcoming joiners, notice periods and reasons for leaving.'],
        'hr_ops' => ['label' => 'HR Ops', 'description' => 'Approvals waiting and decided on time, turnaround, open tickets and the HR queues.', 'whole_organisation' => true],
        'payroll' => ['label' => 'Payroll', 'description' => 'The last finalized payroll month (gross, net paid, deductions, employer cost), cost trend, statutory contributions, deductions, CTC, loans and variable pay.'],
        'helpdesk' => ['label' => 'Helpdesk', 'description' => 'Open tickets, tickets by category and SLA breaches.'],
        'engage' => ['label' => 'Engage', 'description' => 'Badges and reward points.'],
        'nine_box' => ['label' => '9 box', 'description' => 'Counts per box of the latest performance cycle (never names; small boxes suppressed).'],
        'performance' => ['label' => 'Performance', 'description' => 'High performers, rating distribution (small groups suppressed), appraisal status and overdue goals.'],
        'survey' => ['label' => 'Survey', 'description' => 'The latest survey results you may see (respondents and averages; nothing below the privacy threshold).'],
        'pulse' => ['label' => 'Pulse', 'description' => 'The latest pulse results you may see (respondents and averages; nothing below the privacy threshold).'],
        'time_office' => ['label' => 'Time office', 'description' => 'Absenteeism, people on leave, late coming, overtime, absence, regularisations, leave and on-duty days.'],
        'happiness' => ['label' => 'Happiness index', 'description' => 'Average mood and the share of happy check-ins per period and group (small groups suppressed).', 'whole_organisation' => true],
        'expense' => ['label' => 'Expense', 'description' => 'Expense reports waiting, money owed to employees, advances outstanding, and where the money goes.'],
    ];

    /**
     * CA-10: add a preset view. It starts shared with its author only (named viewer) — the author then picks the roles or
     * people, the organisation filter, the e-mail and the payroll refresh day. The reports it needs are added from the
     * report library as shared reports (as for the expense dashboard). Every figure follows each viewer's own rights.
     */
    public function addPreset(string $preset, User $actor, ?string $name = null): Dashboard
    {
        if (! $actor->hasPermission('analytics.manage')) {
            throw new RuntimeException('You cannot add dashboard views.');
        }
        if (! array_key_exists($preset, self::PRESETS)) {
            throw new RuntimeException('Choose one of the preset views.');
        }
        $name = trim((string) ($name ?? '')) ?: self::PRESETS[$preset]['label'].' view';
        $base = Str::limit(Str::slug($name), 56, '') ?: $preset;
        $slug = $base;
        for ($n = 2; Dashboard::query()->where('slug', $slug)->exists(); $n++) {
            $slug = $base.'-'.$n;
        }

        return Dashboard::query()->create([
            'name' => Str::limit($name, 255, ''), 'slug' => $slug, 'preset' => $preset, 'description' => self::PRESETS[$preset]['description'],
            'is_default' => false, 'status' => 'active', 'sort_order' => 50, 'role_ids' => [], 'viewer_user_ids' => [(int) $actor->getKey()],
            'owner_id' => $actor->getKey(), 'widgets' => $this->presetWidgets($preset),
        ]);
    }

    /**
     * The widgets of a preset; report widgets name a report-library template, saved (once) as a shared report.
     *
     * @return list<array<string, mixed>>
     */
    public function presetWidgets(string $preset): array
    {
        $kpi = fn (string $title, string $metric, int $size = 1) => ['type' => 'kpi', 'title' => $title, 'metric' => $metric, 'size' => $size];
        $trend = fn (string $title, string $metric, int $size = 2) => ['type' => 'trend', 'title' => $title, 'metric' => $metric, 'months' => 12, 'size' => $size];
        $report = fn (string $type, string $title, string $template, int $size = 2, array $extra = []) => ['type' => $type, 'title' => $title, 'template' => $template, 'size' => $size] + $extra;

        $widgets = match ($preset) {
            'movement' => [
                $kpi('Total headcount', 'headcount'), $kpi('Joiners (last 30 days)', 'joiners_30d'), $kpi('Exits (last 30 days)', 'exits_30d'), $kpi('Attrition rate (12 months)', 'attrition_rate'),
                $trend('Total headcount', 'headcount'), $kpi('In notice period', 'exits_in_progress'), $kpi('Average tenure (months)', 'avg_tenure_months'),
                $trend('Joiners by month', 'joiners'), $trend('Exits by month', 'exits'),
                $report('table', 'Upcoming joining', 'upcoming_joiners'), $report('table', 'In notice period', 'notice_period_tracker'),
                $report('chart', 'Top reasons for leaving', 'exit_reasons'), $report('chart', 'Exits by department (this financial year)', 'exits_by_department'),
            ],
            'hr_ops' => [
                $kpi('Approvals waiting', 'pending_approvals'), $kpi('Approvals decided on time (30 days)', 'approvals_on_time'), $kpi('Days to decide (30 days)', 'approval_turnaround_days'), $kpi('Open tickets', 'open_tickets'),
                $report('table', 'Leave approvals waiting', 'pending_leave_approvals'), $report('table', 'Regularisations waiting', 'regularisations_pending'),
                $report('table', 'Time-office requests waiting', 'time_office_pending'), $report('table', 'Overdue onboarding tasks', 'overdue_onboarding_tasks'),
                $report('table', 'Letters waiting', 'letters_pending'), $report('table', 'Exit clearances pending', 'clearances_pending'),
            ],
            'payroll' => [
                ['type' => 'payroll', 'title' => 'Last finalized payroll month', 'size' => 4],
                $trend('People cost by month', 'people_cost', 4),
                $report('chart', 'Statutory contributions by month', 'statutory_summary_by_month'), $report('chart', 'Deductions by component', 'deductions_by_component'),
                $report('table', 'CTC by department', 'ctc_by_department'), $report('table', 'Loans outstanding', 'loans_outstanding'),
                $report('table', 'Variable pay paid (this financial year)', 'variable_paid', 4),
            ],
            'helpdesk' => [
                $kpi('Open tickets', 'open_tickets'),
                $report('chart', 'Tickets by category (this month)', 'tickets_by_category', 2), $report('table', 'SLA breaches (this month)', 'sla_breaches', 4),
            ],
            'engage' => [
                $report('leaderboard', 'Top badge holders', 'badges_by_employee', 2, ['limit' => 5]), $report('table', 'Badges given this month', 'badges_ledger_this_month'),
                $report('table', 'Reward points this month', 'reward_points_this_month', 4),
            ],
            'nine_box' => [
                ['type' => 'nine_box', 'title' => '9 box (latest cycle)', 'size' => 4],
            ],
            'performance' => [
                $kpi('High performers (latest cycle)', 'high_performers'),
                $report('chart', 'Rating distribution', 'rating_distribution', 2, ['suppress' => true]), $report('chart', 'Appraisal status', 'appraisal_status'),
                $report('table', 'Overdue goals', 'overdue_goals', 4),
            ],
            'survey' => [['type' => 'survey', 'title' => 'Latest survey', 'survey_kind' => 'survey', 'size' => 4]],
            'pulse' => [['type' => 'survey', 'title' => 'Latest pulse', 'survey_kind' => 'pulse', 'size' => 4]],
            'time_office' => [
                $kpi('Absenteeism (30 days)', 'absenteeism_rate'), $kpi('On leave today', 'on_leave_today'), $trend('Absenteeism by month', 'absenteeism'),
                $report('table', 'Late coming (this month)', 'late_summary_this_month'), $report('chart', 'Overtime hours by department (this month)', 'overtime_by_department'),
                $report('chart', 'Absence by department (last 30 days)', 'absence_by_department'), $report('table', 'Regularisations waiting', 'regularisations_pending'),
                $report('chart', 'Leave availed by type (this year)', 'leave_availed_by_type'), $report('table', 'Work from home and on-duty days (this month)', 'wfh_on_duty_this_month'),
            ],
            'happiness' => [$report('table', 'Happiness index by period', 'happiness_index_trend', 4)],
            'expense' => [
                $kpi('Reports waiting for approval', 'expense_reports_pending'), $kpi('To pay', 'expense_payable'), $kpi('Advances outstanding', 'expense_advances_outstanding'), $kpi('Advances past settle-by', 'expense_advances_overdue'),
                $report('chart', 'Expense by category (this financial year)', 'expense_by_category_this_year'), $report('table', 'Outstanding expense advances', 'outstanding_expense_advances'),
                $report('table', 'Expense payment register', 'expense_payment_register', 4),
            ],
            default => throw new RuntimeException('Choose one of the preset views.'),
        };

        $library = app(ReportLibrary::class);

        return array_map(function (array $widget) use ($library) {
            if (! isset($widget['template'])) {
                return $widget;
            }
            $template = $library->find($widget['template']) ?? throw new RuntimeException("Unknown report template [{$widget['template']}].");
            $widget['report_id'] = Report::query()->firstOrCreate(['name' => $template['name'], 'dataset' => $template['dataset']],
                ['description' => $template['description'], 'definition' => $template['definition'], 'is_shared' => true, 'status' => 'active'])->id;
            unset($widget['template']);

            return $widget;
        }, $widgets);
    }
}
