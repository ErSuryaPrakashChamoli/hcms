<?php

use App\Domain\Analytics\Models\Dashboard;
use App\Domain\Analytics\Models\Report;
use App\Domain\Analytics\Models\ReportRun;
use App\Domain\Analytics\Models\ReportSchedule;
use App\Domain\Analytics\Services\Dashboards;
use App\Domain\Analytics\Services\DatasetRegistry;
use App\Domain\Analytics\Services\ReportExports;
use App\Domain\Analytics\Services\ReportRunner;
use App\Domain\Analytics\Services\ReportSchedules;
use App\Domain\Analytics\Services\WorkforceMetrics;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Identity\Models\Role;
use App\Domain\Organisation\Models\Department;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->company = payrollCompany();
    $this->eng = Department::factory()->create(['name' => 'Engineering', 'code' => 'ENG']);
    $this->fin = Department::factory()->create(['name' => 'Finance', 'code' => 'FIN']);
    $this->a = salariedEmployee(600000);
    $this->b = salariedEmployee(900000);
    $this->c = salariedEmployee(300000);
    foreach ([$this->a, $this->b] as $e) {
        $e->currentPosition()->update(['department_id' => $this->eng->id]);
    }
    $this->c->currentPosition()->update(['department_id' => $this->fin->id]);
});

it('exposes datasets by permission and drops sensitive fields for users without it', function () {
    $registry = app(DatasetRegistry::class);
    $viewer = tenantUser($this->tenant, ['employee.view', 'analytics.reports']);

    expect(array_keys($registry->availableTo($viewer)))->toBe(['employees'])
        ->and(array_keys($registry->availableTo($this->hr)))->toHaveCount(9)
        ->and($registry->get('employees')->catalogue($viewer))->not->toHaveKey('ctc_annual')
        ->and($registry->get('employees')->catalogue($this->hr))->toHaveKey('ctc_annual');

    expect(fn () => app(ReportRunner::class)->execute('payroll', [], $viewer))->toThrow(RuntimeException::class, 'cannot report');
    $result = app(ReportRunner::class)->execute('employees', ['fields' => ['name', 'ctc_annual']], $viewer);
    expect(array_keys($result->columns))->toBe(['name']);
});

it('lists, filters, calculates, groups, sorts, limits and charts', function () {
    $runner = app(ReportRunner::class);

    $list = $runner->execute('employees', ['fields' => ['employee_code', 'department', 'ctc_annual'], 'sort' => ['field' => 'ctc_annual', 'dir' => 'desc']], $this->hr);
    expect($list->total)->toBe(3)->and($list->rows[0]['ctc_annual'])->toBe(900000.0)->and($list->grouped)->toBeFalse();

    $filtered = $runner->execute('employees', ['fields' => ['employee_code'], 'filters' => [['field' => 'department', 'operator' => 'equals', 'value' => 'Engineering'], ['field' => 'ctc_annual', 'operator' => 'gte', 'value' => '700000']]], $this->hr);
    expect($filtered->total)->toBe(1)->and($filtered->rows[0]['employee_code'])->toBe($this->b->employee_code);

    $calc = $runner->execute('employees', ['fields' => ['employee_code', 'ctc_annual'], 'calculated' => [['key' => 'monthly', 'label' => 'Monthly CTC', 'formula' => 'ctc_annual / 12']], 'filters' => [['field' => 'monthly', 'operator' => 'gt', 'value' => '30000']]], $this->hr);
    expect($calc->total)->toBe(2)->and($calc->columns)->toHaveKey('monthly')->and(collect($calc->rows)->pluck('monthly')->sort()->values()->all())->toBe([50000.0, 75000.0]);

    $grouped = $runner->execute('employees', ['fields' => ['department'], 'group_by' => 'department', 'aggregations' => [['fn' => 'count'], ['fn' => 'sum', 'field' => 'ctc_annual'], ['fn' => 'avg', 'field' => 'ctc_annual', 'label' => 'Avg CTC']], 'sort' => ['field' => 'count', 'dir' => 'desc'], 'visualization' => ['type' => 'bar']], $this->hr);
    expect($grouped->grouped)->toBeTrue()->and($grouped->rows[0])->toBe(['department' => 'Engineering', 'count' => 2, 'sum_ctc_annual' => 1500000.0, 'avg_ctc_annual' => 750000.0])
        ->and($grouped->columns['avg_ctc_annual'])->toBe('Avg CTC')
        ->and($grouped->chart['labels'])->toBe(['Engineering', 'Finance'])
        ->and(array_keys($grouped->chart['series']))->toBe(['Count', 'Sum of Annual CTC', 'Avg CTC']);

    $limited = $runner->execute('employees', ['fields' => ['employee_code'], 'limit' => 2], $this->hr);
    expect($limited->total)->toBe(3)->and($limited->rows)->toHaveCount(2);

    $kpi = $runner->execute('employees', ['fields' => ['headcount'], 'visualization' => ['type' => 'kpi', 'y' => 'headcount']], $this->hr);
    expect($kpi->kpi)->toBe(3.0);

    foreach ([['operator' => 'contains', 'value' => 'gin', 'expect' => 2], ['operator' => 'in', 'value' => 'Finance,Sales', 'expect' => 1], ['operator' => 'not_equals', 'value' => 'Finance', 'expect' => 2], ['operator' => 'is_empty', 'value' => null, 'expect' => 0], ['operator' => 'not_empty', 'value' => null, 'expect' => 3]] as $case) {
        expect($runner->execute('employees', ['fields' => ['employee_code'], 'filters' => [['field' => 'department', 'operator' => $case['operator'], 'value' => $case['value']]]], $this->hr)->total)->toBe($case['expect'], $case['operator']);
    }
    expect($runner->execute('employees', ['fields' => ['employee_code'], 'filters' => [['field' => 'joining_date', 'operator' => 'between', 'value' => '2024-12-01,2025-01-31']]], $this->hr)->total)->toBe(3)
        ->and($runner->execute('employees', ['fields' => ['employee_code'], 'filters' => [['field' => 'joining_date', 'operator' => 'last_days', 'value' => '30']]], $this->hr)->total)->toBe(0);
});

it('exports CSV, records runs, and runs due schedules with notifications', function () {
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $report = Report::create(['name' => 'Headcount by department', 'dataset' => 'employees', 'definition' => ['fields' => ['department'], 'group_by' => 'department', 'aggregations' => [['fn' => 'count']]], 'is_shared' => true, 'owner_id' => $this->hr->id]);

    $run = app(ReportExports::class)->export($report, $this->hr);
    expect($run->status)->toBe('completed')->and($run->row_count)->toBe(2)->and($run->hasFile())->toBeTrue();
    $csv = app(ReportExports::class)->contents($run);
    expect($csv)->toContain('Department,Count')->toContain('Engineering,2')->toContain('Finance,1');

    $recipient = tenantUser($this->tenant, ['analytics.view']);
    $schedule = ReportSchedule::create(['report_id' => $report->id, 'frequency' => 'weekly', 'day_of_week' => 1, 'time' => '07:00', 'recipient_user_ids' => [$recipient->id]]);
    expect($schedule->next_run_at->toDateTimeString())->toBe('2026-09-28 07:00:00'); // next Monday 07:00 after Mon 21 Sep 09:00
    expect(app(ReportSchedules::class)->runDue())->toBe(0);

    $this->travelTo('2026-09-28 07:30:00');
    expect(app(ReportSchedules::class)->runDue())->toBe(1)
        ->and(ReportRun::query()->where('report_schedule_id', $schedule->id)->count())->toBe(1)
        ->and($schedule->refresh()->next_run_at->toDateTimeString())->toBe('2026-10-05 07:00:00')
        ->and($recipient->notifications()->first()->data['title'])->toContain('Report ready');

    $monthly = ReportSchedule::create(['report_id' => $report->id, 'frequency' => 'monthly', 'day_of_month' => 1, 'time' => '06:00', 'recipient_user_ids' => []]);
    expect($monthly->next_run_at->toDateTimeString())->toBe('2026-10-01 06:00:00');
});

it('computes workforce metrics and series, and resolves dashboards per viewer', function () {
    $metrics = app(WorkforceMetrics::class);
    expect($metrics->headcount())->toBe(3)->and($metrics->metric('headcount')['value'])->toBe(3);

    foreach (['2026-09-01' => 'present', '2026-09-02' => 'absent', '2026-09-03' => 'present', '2026-09-04' => 'holiday'] as $date => $status) {
        AttendanceRecord::create(['employee_id' => $this->a->id, 'date' => $date, 'status' => $status, 'processed_at' => now()]);
    }
    expect($metrics->absenteeismRate())->toBe(33.3);

    ExitCase::create(['number' => 'EXIT-2026-00001', 'employee_id' => $this->c->id, 'type' => 'resignation', 'status' => 'completed', 'initiated_on' => '2026-08-01', 'last_working_day' => '2026-08-31', 'completed_at' => now()]);
    forceLifecycle($this->c, 'exited', ['exit_date' => '2026-08-31']);
    expect($metrics->headcount())->toBe(2)->and($metrics->headcount('2026-08-15'))->toBe(3)
        ->and($metrics->metric('exits_30d')['value'])->toBe(1)
        ->and($metrics->attritionRate())->toBe(40.0) // 1 exit / avg(3, 2)
        ->and($metrics->series('headcount', 3)['series']['Headcount'])->toBe([3.0, 3.0, 2.0])
        ->and($metrics->series('exits', 2)['series']['Exits'])->toBe([1.0, 0.0]);

    $report = Report::create(['name' => 'By dept', 'dataset' => 'employees', 'definition' => ['fields' => ['department'], 'group_by' => 'department', 'visualization' => ['type' => 'bar']], 'is_shared' => true]);
    $private = Report::create(['name' => 'Private', 'dataset' => 'employees', 'definition' => ['fields' => ['name']], 'is_shared' => false, 'owner_id' => $this->hr->id]);
    $role = Role::factory()->create();
    $dashboard = Dashboard::create(['name' => 'Ops', 'role_ids' => [$role->id], 'widgets' => [
        ['type' => 'kpi', 'title' => 'Headcount', 'metric' => 'headcount', 'size' => 1],
        ['type' => 'trend', 'title' => 'Headcount', 'metric' => 'headcount', 'months' => 3, 'size' => 2],
        ['type' => 'chart', 'title' => 'By dept', 'report_id' => $report->id, 'size' => 2],
        ['type' => 'leaderboard', 'title' => 'Top', 'report_id' => $private->id, 'limit' => 1],
        ['type' => 'alerts', 'title' => 'Attention', 'size' => 2],
    ]]);

    $viewer = tenantUser($this->tenant, ['analytics.view', 'employee.view']);
    expect(app(Dashboards::class)->forUser($viewer))->toHaveCount(1); // the provisioned default only
    $viewer->roles()->attach($role);
    expect(app(Dashboards::class)->forUser($viewer)->pluck('slug')->all())->toContain('ops');

    $widgets = app(Dashboards::class)->render($dashboard, $viewer);
    expect($widgets[0]['metric']['value'])->toBe(2)
        ->and($widgets[1]['chart']['labels'])->toHaveCount(3)
        ->and($widgets[2]['result']->chart['labels'])->toBe(['Engineering', 'Finance'])
        ->and($widgets[3]['error'])->toContain('cannot view')
        ->and($widgets[4]['items'])->toBeArray();
    expect(fn () => app(Dashboards::class)->render($dashboard, tenantUser($this->tenant, ['analytics.view'])))->toThrow(RuntimeException::class, 'not available');
});

it('provisions starter reports and the default dashboard', function () {
    expect(Report::query()->where('is_shared', true)->count())->toBe(6)
        ->and(Dashboard::query()->where('slug', 'hr-overview')->value('is_default'))->toBeTrue()
        ->and(count(Dashboard::query()->where('slug', 'hr-overview')->first()->widgets))->toBe(8);
    $result = app(ReportRunner::class)->run(Report::query()->where('name', 'Headcount by department')->first(), $this->hr);
    expect($result->rows)->toHaveCount(2);
});
