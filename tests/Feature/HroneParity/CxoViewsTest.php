<?php

use App\Domain\Analytics\Models\Dashboard;
use App\Domain\Analytics\Models\Report;
use App\Domain\Analytics\Services\AnalyticsDefaults;
use App\Domain\Analytics\Services\DashboardDeliveries;
use App\Domain\Analytics\Services\Dashboards;
use App\Domain\Analytics\Services\DashboardViews;
use App\Domain\Analytics\Services\ReportLibrary;
use App\Domain\Analytics\Services\ReportResult;
use App\Domain\Analytics\Services\WorkforceMetrics;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Services\SetupCatalogue;
use App\Domain\Employment\Models\Employee;
use App\Domain\Engagement\Services\SurveyResponses;
use App\Domain\Engagement\Services\Surveys;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Services\Appraisals;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Workflow\Enums\TaskStatus;
use App\Domain\Workflow\Models\WorkflowTask;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Filament\Pages\DashboardViewer;
use App\Filament\Resources\Dashboards\DashboardResource;
use App\Filament\Resources\Dashboards\Pages\EditDashboard;
use App\Filament\Resources\Dashboards\Pages\ListDashboards;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/../Engagement/EngagementTestHelpers.php';

/* HROne parity CA-10 — Setup → Access rights → CXO access: shared dashboard views. A view is listed for its roles and named
 * people, shows only its organisation slice (a constraint on top of each viewer's own access, never a grant), is e-mailed
 * as a link (never figures), and moves its payroll figures on a refresh day (finalized payroll only, through the payroll
 * read contract). Presets per HROne dashboard; small groups stay suppressed. Defaults keep every dashboard as it was. */

beforeEach(function () {
    $this->travelTo('2026-10-20 09:00:00');
    Mail::fake();
    $this->tenant = provisionTenant('Cxo A');
    actAsTenant($this->tenant);
    Filament::getPanel('admin')->boot();
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->eng = Department::factory()->create(['name' => 'Engineering', 'code' => 'ENG']);
    $this->fin = Department::factory()->create(['name' => 'Finance', 'code' => 'FIN']);
    $this->engineers = collect(range(1, 6))->map(fn () => cxoPerson($this->eng));
    $this->financiers = collect(range(1, 2))->map(fn () => cxoPerson($this->fin));
    $this->dashboards = app(Dashboards::class);
});

function cxoPerson(Department $department): Employee
{
    $employee = activeEmployee();
    $employee->currentPosition()->update(['department_id' => $department->id]);

    return $employee->refresh();
}

/** A view of the given widgets (whole organisation unless $attributes say otherwise). */
function cxoView(array $widgets, array $attributes = []): Dashboard
{
    return Dashboard::query()->create($attributes + ['name' => 'View '.uniqid(), 'widgets' => $widgets]);
}

function cxoKpi(string $metric): array
{
    return ['type' => 'kpi', 'title' => $metric, 'metric' => $metric, 'size' => 1];
}

function cxoReport(string $template, string $type = 'table', array $extra = []): array
{
    $t = app(ReportLibrary::class)->find($template);
    $report = Report::query()->firstOrCreate(['name' => $t['name'], 'dataset' => $t['dataset']], ['definition' => $t['definition'], 'is_shared' => true, 'status' => 'active']);

    return ['type' => $type, 'title' => $template, 'report_id' => $report->id, 'size' => 2] + $extra;
}

/** A finalized payroll month: entries of [employee, employer cost]. */
function cxoPayrollRun(int $year, int $month, string $finalizedAt, array $entries): PayrollRun
{
    $company = Company::query()->orderBy('id')->first();
    $start = Carbon::create($year, $month, 1);
    $period = PayrollPeriod::query()->create(['company_id' => $company->id, 'year' => $year, 'month' => $month, 'start_date' => $start->toDateString(), 'end_date' => $start->copy()->endOfMonth()->toDateString(), 'payment_date' => $start->copy()->endOfMonth()->toDateString(), 'status' => 'closed']);
    $run = PayrollRun::query()->create(['company_id' => $company->id, 'payroll_period_id' => $period->id, 'status' => 'finalized', 'finalized_at' => $finalizedAt, 'totals' => ['employer_cost' => 999999]]);
    foreach ($entries as [$employee, $cost]) {
        PayrollEntry::query()->create(['payroll_run_id' => $run->id, 'employee_id' => $employee->id, 'days_in_period' => 30, 'paid_days' => 30, 'lop_days' => 0, 'gross' => $cost - 1000, 'total_earnings' => $cost - 1000,
            'total_deductions' => 500, 'net_pay' => $cost - 1500, 'employer_cost' => $cost, 'taxable_earnings' => $cost - 1000, 'status' => 'calculated']);
    }

    return $run;
}

/** @return array<string, mixed> the rendered widgets keyed by title */
function cxoRender(Dashboard $view, $user): array
{
    return collect(app(Dashboards::class)->render($view->fresh(), $user))->keyBy('title')->all();
}

it('keeps every dashboard as it was: no view fields, the role audience, the same figures, nothing seeded', function () {
    $overview = Dashboard::query()->where('slug', 'hr-overview')->firstOrFail();
    $raw = $overview->getAttributes();
    foreach (['preset', 'applicability', 'viewer_user_ids', 'email_user_ids', 'email_frequency', 'email_next_run_at', 'refresh_day'] as $column) {
        expect($raw[$column])->toBeNull();
    }
    expect($overview->isView())->toBeFalse()->and(Dashboard::query()->count())->toBe(1);

    $before = $this->dashboards->render($overview, $this->hr);
    expect($before[0]['metric'])->toBe(app(WorkforceMetrics::class)->metric('headcount', $this->hr))->and($before[0]['metric']['value'])->toBe(8);

    // A sliced view rendered in between leaves nothing behind.
    cxoRender(cxoView([cxoKpi('headcount')], ['applicability' => ['department' => [$this->eng->id]]]), $this->hr);
    expect(app(AccessScopes::class)->slicing())->toBeFalse()
        ->and(serialize($this->dashboards->render($overview->fresh(), $this->hr)))->toBe(serialize($before))
        ->and(Employee::query()->count())->toBe(8);

    // Role audience exactly as before: no roles = everyone with analytics.view; roles = their holders.
    $role = Role::factory()->create();
    $board = cxoView([cxoKpi('headcount')], ['role_ids' => [$role->id]]);
    $viewer = tenantUser($this->tenant, ['analytics.view']);
    expect($board->isForUser($viewer))->toBeFalse()->and($overview->isForUser($viewer))->toBeTrue();
    $viewer->roles()->attach($role);
    expect($board->fresh()->isForUser($viewer->fresh()))->toBeTrue();
});

it('lists a view for its named people besides its roles, only them when no role is named, and grants no figure', function () {
    $named = tenantUser($this->tenant, ['analytics.view']);
    $other = tenantUser($this->tenant, ['analytics.view']);
    $role = Role::factory()->create();
    $holder = tenantUser($this->tenant, ['analytics.view']);
    $holder->roles()->attach($role);

    $private = cxoView([cxoKpi('people_cost'), cxoKpi('headcount')], ['name' => 'Board pack', 'viewer_user_ids' => [$named->id]]);
    $mixed = cxoView([cxoKpi('headcount')], ['name' => 'Mixed', 'role_ids' => [$role->id], 'viewer_user_ids' => [$named->id]]);
    expect($this->dashboards->forUser($named)->pluck('name')->all())->toContain('Board pack', 'Mixed')
        ->and($this->dashboards->forUser($other)->pluck('name')->all())->not->toContain('Board pack')
        ->and($this->dashboards->forUser($other)->pluck('name')->all())->not->toContain('Mixed')
        ->and($this->dashboards->forUser($holder->fresh())->pluck('name')->all())->toContain('Mixed')
        ->and($this->dashboards->forUser($holder->fresh())->pluck('name')->all())->not->toContain('Board pack');
    expect(fn () => $this->dashboards->render($private, $other))->toThrow(RuntimeException::class, 'not available');

    // Being named grants nothing: the payroll figure stays restricted for someone without its right.
    $widgets = cxoRender($private, $named);
    expect($widgets['people_cost']['metric']['restricted'] ?? false)->toBeTrue()->and($widgets['people_cost']['metric']['value'])->toBeNull();

    // A named person without the right to open dashboards still cannot.
    $noRight = tenantUser($this->tenant, ['employee.view']);
    $private->update(['viewer_user_ids' => [$named->id, $noRight->id]]);
    $this->actingAs($noRight);
    $this->get(DashboardViewer::getUrl(['dashboard' => $private->slug]))->assertForbidden();
    $this->actingAs($this->hr);

    // A malformed list names nobody and still restricts the view (fail closed).
    Dashboard::query()->whereKey($private->id)->update(['viewer_user_ids' => '"everyone"']);
    expect($private->fresh()->namedViewers())->toBe([])->and($private->fresh()->isForUser($named))->toBeFalse()->and($private->fresh()->isForUser($other))->toBeFalse();
});

it('narrows every figure to the view\'s slice and never past the viewer\'s own access', function () {
    $view = cxoView([cxoKpi('headcount'), cxoReport('headcount_department', 'chart'), cxoKpi('pending_approvals'), ['type' => 'alerts', 'title' => 'alerts'], cxoReport('user_access_review')],
        ['applicability' => ['department' => [$this->eng->id]]]);
    $widgets = cxoRender($view, $this->hr);
    expect($widgets['headcount']['metric']['value'])->toBe(6)
        ->and($widgets['headcount_department']['result']->chart['labels'])->toBe(['Engineering'])
        // Figures the slice cannot narrow are not shown in a sliced view.
        ->and($widgets['pending_approvals']['error'])->toBe(Dashboards::NOT_IN_SLICE)
        ->and($widgets['alerts']['error'])->toBe(Dashboards::NOT_IN_SLICE)
        ->and($widgets['user_access_review']['error'])->toBe(Dashboards::NOT_IN_SLICE);

    // The same widgets over the whole organisation.
    $whole = cxoRender(cxoView($view->widgets), $this->hr);
    expect($whole['headcount']['metric']['value'])->toBe(8)->and($whole['pending_approvals']['error'])->toBeNull()
        ->and($whole['headcount_department']['result']->chart['labels'])->toBe(['Engineering', 'Finance']);

    // A viewer limited to Finance sees nothing of an Engineering view, and their own part of a Finance one.
    $scoped = tenantUser($this->tenant, ['analytics.view', 'employee.view']);
    app(AccessScopes::class)->assign($scoped, ['department' => [$this->fin->id]], 'Finance only');
    expect(cxoRender($view, $scoped)['headcount']['metric']['value'])->toBe(0)
        ->and(cxoRender(cxoView([cxoKpi('headcount')], ['applicability' => ['department' => [$this->fin->id, $this->eng->id]]]), $scoped)['headcount']['metric']['value'])->toBe(2)
        ->and(cxoRender(cxoView([cxoKpi('headcount')]), $scoped)['headcount']['metric']['value'])->toBe(2);

    // Malformed slices and slices on whole-organisation presets show nothing.
    $bad = cxoView([cxoKpi('headcount')]);
    Dashboard::query()->whereKey($bad->id)->update(['applicability' => json_encode(['planet' => [1]])]);
    expect(cxoRender($bad, $this->hr)['headcount']['error'])->toContain('not valid');
    Dashboard::query()->whereKey($bad->id)->update(['applicability' => json_encode(['department' => []])]);
    expect(cxoRender($bad, $this->hr)['headcount']['error'])->toContain('not valid');
    $ops = cxoView([cxoKpi('pending_approvals')], ['preset' => 'hr_ops', 'applicability' => ['department' => [$this->eng->id]]]);
    expect(cxoRender($ops, $this->hr)['pending_approvals']['error'])->toContain('whole organisation only');

    // While a slice is active, a query without a signed-in tenant user returns nothing; afterwards everything is back.
    $count = app(AccessScopes::class)->withinSlice(['department' => [$this->eng->id]], function () {
        $user = auth()->user();
        auth()->forgetUser();
        $n = Employee::query()->count();
        auth()->setUser($user);

        return $n;
    });
    expect($count)->toBe(0)->and(app(AccessScopes::class)->withinSlice(null, fn () => Employee::query()->count()))->toBe(0)
        ->and(Employee::query()->count())->toBe(8)->and(app(AccessScopes::class)->slicing())->toBeFalse();
});

it('renders a view as its viewer whoever asks, and gives the signed-in user back', function () {
    $scoped = tenantUser($this->tenant, ['analytics.view', 'employee.view']);
    app(AccessScopes::class)->assign($scoped, ['department' => [$this->fin->id]], 'Finance only');
    $view = cxoView([cxoKpi('headcount')], ['preset' => 'movement']);

    expect(cxoRender($view, $scoped)['headcount']['metric']['value'])->toBe(2)
        ->and(auth()->id())->toBe($this->hr->id)
        ->and(cxoRender($view, $this->hr)['headcount']['metric']['value'])->toBe(8);
});

it('reads payroll figures from finalized payroll only, as of the refresh day, within the slice and suppressed for small groups', function () {
    $august = array_merge($this->engineers->map(fn ($e) => [$e, 10000])->all(), $this->financiers->map(fn ($e) => [$e, 20000])->all());
    $september = array_merge($this->engineers->map(fn ($e) => [$e, 11000])->all(), $this->financiers->map(fn ($e) => [$e, 21000])->all());
    cxoPayrollRun(2026, 8, '2026-09-03 10:00:00', $august);
    cxoPayrollRun(2026, 9, '2026-10-08 10:00:00', $september);
    $draftPeriod = PayrollPeriod::query()->create(['company_id' => Company::query()->orderBy('id')->value('id'), 'year' => 2026, 'month' => 10, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'payment_date' => '2026-10-31', 'status' => 'open']);
    $draft = PayrollRun::query()->create(['company_id' => $draftPeriod->company_id, 'payroll_period_id' => $draftPeriod->id, 'status' => 'calculated', 'totals' => []]);
    PayrollEntry::query()->create(['payroll_run_id' => $draft->id, 'employee_id' => $this->engineers[0]->id, 'days_in_period' => 31, 'paid_days' => 31, 'lop_days' => 0, 'gross' => 1, 'total_earnings' => 1, 'total_deductions' => 0, 'net_pay' => 1, 'employer_cost' => 777777, 'taxable_earnings' => 1, 'status' => 'calculated']);

    $widgets = [cxoKpi('people_cost'), cxoKpi('cost_per_head'), ['type' => 'payroll', 'title' => 'payroll', 'size' => 4], cxoReport('payroll_cost_by_period', 'table')];
    $live = cxoView($widgets, ['preset' => 'payroll']);
    $rendered = cxoRender($live, $this->hr);
    expect($rendered['people_cost']['metric']['value'])->toBe(108000.0)->and($rendered['people_cost']['metric']['hint'])->toBe('Sep 2026')
        ->and($rendered['cost_per_head']['metric']['value'])->toBe(13500.0)
        ->and($rendered['payroll']['payroll'])->toMatchArray(['period' => 'Sep 2026', 'employees' => 8, 'employer_cost' => 108000.0, 'net_pay' => 96000.0, 'total_deductions' => 4000.0, 'suppressed' => false])
        ->and($rendered['payroll_cost_by_period']['result']->rows)->toHaveCount(2);

    // Refresh day 5: September (finalized 8 Oct) waits for 5 Nov; refresh day 10 already shows it.
    $frozen = cxoView($widgets, ['preset' => 'payroll', 'refresh_day' => 5]);
    $rendered = cxoRender($frozen, $this->hr);
    expect($frozen->payrollAsOf()->toDateTimeString())->toBe('2026-10-05 00:00:00')
        ->and($rendered['people_cost']['metric']['value'])->toBe(100000.0)->and($rendered['people_cost']['metric']['hint'])->toContain('Aug 2026')
        ->and($rendered['payroll']['payroll']['period'])->toBe('Aug 2026')
        ->and(collect($rendered['payroll_cost_by_period']['result']->rows)->pluck('period')->all())->toBe(['2026-08'])
        ->and(cxoRender(cxoView($widgets, ['preset' => 'payroll', 'refresh_day' => 10]), $this->hr)['people_cost']['metric']['value'])->toBe(108000.0);
    $this->travelTo('2026-11-06 09:00:00');
    expect(cxoRender($frozen, $this->hr)['people_cost']['metric']['value'])->toBe(108000.0);
    $this->travelTo('2026-10-20 09:00:00');

    // The slice: Engineering's entries only (never the run totals).
    $engineering = cxoView($widgets, ['preset' => 'payroll', 'applicability' => ['department' => [$this->eng->id]]]);
    $rendered = cxoRender($engineering, $this->hr);
    expect($rendered['people_cost']['metric']['value'])->toBe(66000.0)->and($rendered['cost_per_head']['metric']['value'])->toBe(11000.0)
        ->and($rendered['payroll']['payroll']['employees'])->toBe(6);

    // Aggregate viewers (compensation.analytics, no payroll.view): Finance (2 people) is suppressed, Engineering (6) is not.
    $cxo = tenantUser($this->tenant, ['analytics.view', 'compensation.analytics']);
    $finance = cxoView($widgets, ['preset' => 'payroll', 'applicability' => ['department' => [$this->fin->id]]]);
    $rendered = cxoRender($finance, $cxo);
    expect($rendered['people_cost']['metric']['value'])->toBeNull()->and($rendered['people_cost']['metric']['hint'])->toContain('Suppressed')
        ->and($rendered['payroll']['payroll'])->toMatchArray(['suppressed' => true])->and($rendered['payroll']['payroll'])->not->toHaveKey('employer_cost')
        ->and($rendered['payroll_cost_by_period']['error'])->toContain('cannot report')
        ->and(cxoRender($engineering, $cxo)['people_cost']['metric']['value'])->toBe(66000.0)
        ->and(cxoRender($finance, $this->hr)['people_cost']['metric']['value'])->toBe(42000.0);

    // A malformed refresh day stops the payroll figures only.
    Dashboard::query()->whereKey($frozen->id)->update(['refresh_day' => 31]);
    $rendered = cxoRender($frozen, $this->hr);
    expect($rendered['people_cost']['error'])->toContain('refresh day')->and($rendered['payroll']['error'])->toContain('refresh day')
        ->and($rendered['payroll_cost_by_period']['error'])->toContain('refresh day');

    // A trend of a protected figure needs its right on every dashboard (it used to show to any dashboard viewer).
    $plain = cxoView([['type' => 'trend', 'title' => 'cost', 'metric' => 'people_cost', 'months' => 3, 'size' => 2]]);
    expect(cxoRender($plain, tenantUser($this->tenant, ['analytics.view']))['cost']['error'])->toContain('Restricted')
        ->and(cxoRender(cxoView($plain->widgets, ['preset' => 'payroll']), $this->hr)['cost']['chart']['series']['People cost (last finalized payroll)'])->toBe([100000.0, 108000.0, 0.0]);
});

it('e-mails a view as a link on its cadence, to the recipients who may open it, audited and without figures', function () {
    $named = tenantUser($this->tenant, ['analytics.view']);
    $outsider = tenantUser($this->tenant, ['analytics.view']);
    $suspended = tenantUser($this->tenant, ['analytics.view'], ['status' => UserStatus::Suspended]);
    $view = cxoView([cxoKpi('headcount')], ['name' => 'Board pack', 'preset' => 'movement', 'viewer_user_ids' => [$named->id, $suspended->id],
        'email_user_ids' => [$named->id, $outsider->id, $suspended->id], 'email_frequency' => 'weekly', 'email_day_of_week' => 1, 'email_time' => '07:00']);
    expect($view->fresh()->email_next_run_at->toDateTimeString())->toBe('2026-10-26 07:00:00');

    $deliveries = app(DashboardDeliveries::class);
    expect($deliveries->runDue())->toBe(0);
    $this->travelTo('2026-10-26 07:30:00');
    expect($deliveries->runDue())->toBe(1)->and($deliveries->runDue())->toBe(0)
        ->and($view->fresh()->email_next_run_at->toDateTimeString())->toBe('2026-11-02 07:00:00');

    $sent = NotificationDelivery::query()->where('event', DashboardDeliveries::EVENT)->get();
    expect($sent->pluck('user_id')->unique()->values()->all())->toBe([$named->id])
        ->and($sent->pluck('channel')->sort()->values()->all())->toBe(['email', 'in_app'])
        ->and($sent->first()->body)->toContain(DashboardViewer::getUrl(['dashboard' => $view->slug]))->toContain('nothing is attached')
        ->and($sent->first()->body)->not->toContain('Headcount');
    $audit = AuditEvent::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('action', AuditAction::Export->value)
        ->where('entity_type', Dashboard::class)->latest('id')->firstOrFail();
    expect($audit->metadata['channel'])->toBe('view_notice')->and($audit->metadata['recipients'])->toBe([$named->id])
        ->and($audit->metadata['skipped'])->toEqualCanonicalizing([$outsider->id, $suspended->id])->and($audit->metadata['scheduled'])->toBeTrue();

    // The organisation's mail setting: in-app only. "Send now" needs analytics.manage.
    app(SettingsRepository::class)->set('analytics.views.email', false, 'In-app only');
    $result = $deliveries->deliver($view->fresh(), $this->hr);
    expect($result['sent'])->toBe([$named->id])
        ->and(NotificationDelivery::query()->where('event', DashboardDeliveries::EVENT)->latest('id')->value('channel'))->toBe('in_app')
        ->and(fn () => $deliveries->deliver($view->fresh(), $named))->toThrow(RuntimeException::class, 'cannot send');

    // An inactive view sends nothing; the scheduler command runs the views with the report schedules.
    $view->update(['status' => 'inactive']);
    expect($view->fresh()->email_next_run_at)->toBeNull()->and($deliveries->deliver($view->fresh())['sent'])->toBe([]);
    $view->update(['status' => 'active']);
    $this->travelTo('2026-11-02 08:00:00');
    actAsTenant(null);
    $this->artisan('peopleos:reports:run-due', ['--tenant' => $this->tenant->id])->expectsOutputToContain('1 dashboard view notice(s) sent')->assertSuccessful();
    actAsTenant($this->tenant);
});

it('offers every preset as a view shared with its author, each rendering, with ROI waiting for its data', function () {
    $defaults = app(AnalyticsDefaults::class);
    expect(array_keys(AnalyticsDefaults::PRESETS))->toBe(['movement', 'hr_ops', 'payroll', 'helpdesk', 'engage', 'nine_box', 'performance', 'survey', 'pulse', 'time_office', 'happiness', 'expense'])
        ->and(fn () => $defaults->addPreset('roi', $this->hr))->toThrow(RuntimeException::class, 'preset')
        ->and(fn () => $defaults->addPreset('movement', tenantUser($this->tenant, ['analytics.view'])))->toThrow(RuntimeException::class, 'cannot add');

    $other = tenantUser($this->tenant, ['analytics.view']);
    foreach (array_keys(AnalyticsDefaults::PRESETS) as $preset) {
        $view = $defaults->addPreset($preset, $this->hr);
        expect($view->preset)->toBe($preset)->and($view->viewer_user_ids)->toBe([$this->hr->id])->and($view->role_ids)->toBe([])
            ->and($view->isForUser($other))->toBeFalse()
            ->and(collect($view->widgets)->every(fn ($w) => ! isset($w['template'])))->toBeTrue();
        foreach ($this->dashboards->render($view, $this->hr) as $widget) {
            expect($widget['error'] === null || str_starts_with($widget['error'], 'Suppressed'))->toBeTrue("{$preset}: {$widget['title']}: {$widget['error']}");
        }
    }
    // Adding a preset twice makes a second view with its own slug; the reports are shared, not duplicated.
    $again = $defaults->addPreset('movement', $this->hr, 'Movement — South');
    expect($again->slug)->toBe('movement-south')->and($defaults->addPreset('movement', $this->hr)->slug)->toBe('employee-movement-view-2')
        ->and(Report::query()->where('name', 'Upcoming joiners (next 30 days)')->count())->toBe(1);
});

it('measures approval turnaround for the HR Ops view, over the whole organisation only', function () {
    [$nodes, $edges] = linear([['id' => 'wait', 'type' => 'wait', 'name' => 'Wait', 'config' => ['hours' => 100]]]);
    $run = app(WorkflowEngine::class)->start(publishWorkflow($nodes, $edges), $this->engineers[0]);
    $task = function (string $created, ?string $due, ?string $completed, TaskStatus $status = TaskStatus::Approved) use ($run) {
        $this->travelTo($created);
        WorkflowTask::query()->create(['workflow_instance_id' => $run->id, 'node_id' => 'x', 'type' => 'approval', 'title' => 'Approve', 'assignee_id' => $this->hr->id, 'sequence' => 1,
            'status' => $status, 'due_at' => $due, 'completed_at' => $completed]);
    };
    $task('2026-10-10 09:00:00', '2026-10-12 09:00:00', '2026-10-11 09:00:00');                     // on time, 1 day
    $task('2026-10-10 09:00:00', '2026-10-11 09:00:00', '2026-10-13 09:00:00', TaskStatus::Rejected); // late, 3 days
    $task('2026-08-01 09:00:00', '2026-08-02 09:00:00', '2026-08-02 09:00:00');                     // older than 30 days
    $task('2026-10-15 09:00:00', '2026-10-16 09:00:00', null, TaskStatus::Pending);                 // not decided
    $this->travelTo('2026-10-20 09:00:00');

    $view = app(AnalyticsDefaults::class)->addPreset('hr_ops', $this->hr);
    $widgets = collect($this->dashboards->render($view, $this->hr))->keyBy(fn ($w) => $w['title']);
    expect($widgets['Approvals decided on time (30 days)']['metric']['value'])->toBe(50.0)
        ->and($widgets['Days to decide (30 days)']['metric']['value'])->toBe(2.0)
        ->and(app(WorkforceMetrics::class)->metric('approvals_on_time', tenantUser($this->tenant, ['analytics.view']))['restricted'] ?? false)->toBeTrue();
});

it('keeps the 9-box and grouped counts suppressed: no box or group below the threshold, with complementary suppression', function () {
    $cycle = app(Appraisals::class)->launch(draftCycle(['code' => 'NB', 'name' => 'Cycle NB', 'stages' => [['key' => 'final', 'name' => 'Final rating', 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-31']]]));
    foreach ($this->engineers as $e) {
        Appraisal::query()->where('performance_cycle_id', $cycle->id)->where('employee_id', $e->id)->update(['computed_rating' => 5, 'competency_score' => 5]);
    }
    Appraisal::query()->where('performance_cycle_id', $cycle->id)->where('employee_id', $this->financiers[0]->id)->update(['computed_rating' => 3, 'competency_score' => 3]);

    $box = ['type' => 'nine_box', 'title' => 'box', 'size' => 4];
    $whole = cxoRender(cxoView([$box], ['preset' => 'nine_box']), $this->hr)['box']['nine_box'];
    // 6 future leaders and 1 core employee: the 1 is below 5, and total minus the 6 would reveal it, so both are hidden.
    expect($whole['placed'])->toBe(7)->and($whole['boxes']['high_high']['count'])->toBeNull()->and($whole['boxes']['moderate_moderate']['count'])->toBeNull()
        ->and($whole['boxes']['low_low']['count'])->toBe(0)->and(collect($whole['boxes'])->pluck('count')->filter()->all())->toBe([]);
    $engineering = cxoRender(cxoView([$box], ['preset' => 'nine_box', 'applicability' => ['department' => [$this->eng->id]]]), $this->hr)['box']['nine_box'];
    expect($engineering['placed'])->toBe(6)->and($engineering['boxes']['high_high']['count'])->toBe(6);
    $finance = cxoRender(cxoView([$box], ['preset' => 'nine_box', 'applicability' => ['department' => [$this->fin->id]]]), $this->hr)['box']['nine_box'];
    expect($finance['suppressed'])->toBeTrue()->and($finance['placed'])->toBeNull()
        ->and(cxoRender(cxoView([$box]), tenantUser($this->tenant, ['analytics.view']))['box']['error'])->toContain('Restricted');

    $result = new ReportResult(['dept' => 'Department', 'count' => 'Count'], [['dept' => 'A', 'count' => 6], ['dept' => 'B', 'count' => 2], ['dept' => 'C', 'count' => 5]], 3, true,
        ['labels' => ['A', 'B', 'C'], 'series' => ['Count' => [6.0, 2.0, 5.0]]]);
    $suppressed = Dashboards::suppressSmallGroups($result);
    expect($suppressed->rows)->toBe([['dept' => 'A', 'count' => 6], ['dept' => 'Fewer than 5 each (2 groups)', 'count' => null]])
        ->and($suppressed->chart)->toBe(['labels' => ['A'], 'series' => ['Count' => [6.0]]])
        ->and(Dashboards::suppressSmallGroups(new ReportResult(['dept' => 'D', 'count' => 'C'], [['dept' => 'A', 'count' => 5], ['dept' => 'B', 'count' => 6]], 2, true))->rows)->toHaveCount(2)
        ->and(fn () => Dashboards::suppressSmallGroups(new ReportResult(['dept' => 'D', 'count' => 'C'], [['dept' => 'A', 'count' => 4]], 1, true)))->toThrow(RuntimeException::class, 'Suppressed')
        ->and(fn () => Dashboards::suppressSmallGroups(new ReportResult(['name' => 'Name'], [['name' => 'x']], 1, false)))->toThrow(RuntimeException::class, 'grouped counts');
});

it('shows survey and pulse results only above the threshold and only for a population inside the view', function () {
    $actors = engagementActors();
    $team = engagementTeam(6, $this->eng);
    $pulse = openEngagementSurvey($actors, ['name' => 'Pulse check', 'survey_type' => 'pulse'], [['key' => 'mood', 'type' => 'likert', 'prompt' => 'I enjoy my work', 'required' => true]]);
    foreach ($team as $member) {
        app(SurveyResponses::class)->submit($pulse, $member->user, ['mood' => '4']);
    }
    app(Surveys::class)->close($pulse, $actors['preparer']);
    $widget = ['type' => 'survey', 'title' => 'pulse', 'survey_kind' => 'pulse', 'size' => 4];

    $shown = cxoRender(cxoView([$widget], ['preset' => 'pulse']), $actors['analyst'])['pulse']['survey'];
    expect($shown)->toMatchArray(['name' => 'Pulse check', 'respondents' => 6, 'suppressed' => false])
        ->and($shown['questions'])->toBe([['prompt' => 'I enjoy my work', 'average' => 4.0, 'answered' => 6]])
        // Not a pulse: the survey widget looks at the other surveys only. A slice never filters anonymous results ad hoc:
        // a survey whose population reaches outside the slice is not shown in it.
        ->and(cxoRender(cxoView([['type' => 'survey', 'title' => 's', 'survey_kind' => 'survey']], ['preset' => 'survey']), $actors['analyst'])['s']['survey'])->toBeNull()
        ->and(cxoRender(cxoView([$widget], ['preset' => 'pulse', 'applicability' => ['department' => [$this->eng->id]]]), $actors['analyst'])['pulse']['survey'])->toBeNull()
        ->and(cxoRender(cxoView([$widget], ['preset' => 'pulse']), tenantUser($this->tenant, ['analytics.view']))['pulse']['error'])->toContain('Restricted');

    $small = openEngagementSurvey($actors, ['name' => 'Tiny pulse', 'survey_type' => 'pulse'], [['key' => 'mood', 'type' => 'likert', 'prompt' => 'I enjoy my work', 'required' => true]], 'TINY');
    foreach (array_slice($team, 0, 4) as $member) {
        app(SurveyResponses::class)->submit($small, $member->user, ['mood' => '1']);
    }
    $this->travelTo('2026-10-21 09:00:00');
    app(Surveys::class)->close($small, $actors['preparer']);
    $latest = cxoRender(cxoView([$widget], ['preset' => 'pulse']), $actors['analyst'])['pulse']['survey'];
    expect($latest)->toMatchArray(['name' => 'Tiny pulse', 'respondents' => null, 'suppressed' => true, 'response_rate' => null, 'questions' => []]);
});

it('builds, edits, clones, inactivates and sends views on the dashboard screens', function () {
    Livewire::test(ListDashboards::class)->assertOk()->callAction('addView', ['preset' => 'helpdesk', 'name' => 'Helpdesk — Engineering'])->assertHasNoActionErrors();
    $view = Dashboard::query()->where('preset', 'helpdesk')->firstOrFail();
    expect($view->name)->toBe('Helpdesk — Engineering')->and($view->owner_id)->toBe($this->hr->id);

    $named = tenantUser($this->tenant, ['analytics.view']);
    Livewire::test(EditDashboard::class, ['record' => $view->id])
        ->fillForm(['applicability_dimension' => 'department', 'applicability_ids' => [(string) $this->eng->id], 'viewer_user_ids' => [(string) $named->id],
            'email_user_ids' => [(string) $named->id], 'email_frequency' => 'monthly', 'email_day_of_month' => 3, 'email_time' => '08:30', 'refresh_day' => 7, 'audit_reason' => 'CXO pack'])
        ->call('save')->assertHasNoFormErrors();
    $view->refresh();
    expect($view->applicability)->toBe(['department' => [$this->eng->id]])->and($view->viewer_user_ids)->toBe([$named->id])
        ->and($view->email_frequency)->toBe('monthly')->and($view->email_next_run_at->toDateTimeString())->toBe('2026-11-03 08:30:00')
        ->and($view->refresh_day)->toBe(7)->and(app(DashboardViews::class)->describe($view))->toBe('Departments: Engineering');

    Livewire::test(EditDashboard::class, ['record' => $view->id])->assertFormSet(['applicability_dimension' => 'department', 'applicability_ids' => [$this->eng->id]])
        ->fillForm(['applicability_ids' => ['999999'], 'audit_reason' => 'Checking the form'])->call('save')->assertHasErrors();
    expect($view->fresh()->applicability)->toBe(['department' => [$this->eng->id]]);
    // Only values the author can see: someone limited to Finance cannot name Engineering.
    $finOnly = tenantUser($this->tenant, ['analytics.manage', 'analytics.view']);
    app(AccessScopes::class)->assign($finOnly, ['department' => [$this->fin->id]], 'Finance only');
    $this->actingAs($finOnly);
    expect(fn () => app(DashboardViews::class)->normalise(['applicability_dimension' => 'department', 'applicability_ids' => [$this->eng->id]], null, $finOnly))
        ->toThrow(ValidationException::class)
        ->and(app(DashboardViews::class)->normalise(['applicability_dimension' => 'department', 'applicability_ids' => [$this->fin->id]], null, $finOnly)['applicability'])->toBe(['department' => [$this->fin->id]]);
    $this->actingAs($this->hr);
    Livewire::test(EditDashboard::class, ['record' => $view->id])->fillForm(['email_user_ids' => [], 'email_frequency' => 'weekly', 'email_day_of_week' => 2, 'audit_reason' => 'Checking the form'])
        ->call('save')->assertHasFormErrors(['email_user_ids']);
    Livewire::test(EditDashboard::class, ['record' => $view->id])->fillForm(['refresh_day' => 30, 'audit_reason' => 'Checking the form'])->call('save')->assertHasFormErrors(['refresh_day']);
    // Whole-organisation presets take no organisation filter (the field is locked on the form, and refused by the service).
    $ops = app(AnalyticsDefaults::class)->addPreset('hr_ops', $this->hr);
    Livewire::test(EditDashboard::class, ['record' => $ops->id])->assertFormFieldIsDisabled('applicability_dimension');
    expect(fn () => app(DashboardViews::class)->normalise(['applicability_dimension' => 'department', 'applicability_ids' => [$this->eng->id]], 'hr_ops', $this->hr))
        ->toThrow(ValidationException::class);

    Livewire::test(ListDashboards::class)->callTableAction('clone', $view)->assertHasNoTableActionErrors();
    $copy = Dashboard::query()->where('name', 'Copy of Helpdesk — Engineering')->firstOrFail();
    expect($copy->status)->toBe('draft')->and($copy->applicability)->toBe(['department' => [$this->eng->id]])->and($copy->email_next_run_at)->toBeNull()
        ->and($copy->is_default)->toBeFalse()->and($copy->isForUser($named))->toBeTrue()
        ->and($this->dashboards->forUser($named)->pluck('id')->all())->not->toContain($copy->id);

    $overview = Dashboard::query()->where('slug', 'hr-overview')->firstOrFail();
    Livewire::test(ListDashboards::class)->assertTableActionHidden('inactivate', $overview)->callTableAction('inactivate', $view);
    expect($view->fresh()->status)->toBe('inactive')->and($view->fresh()->email_next_run_at)->toBeNull()
        ->and(fn () => app(DashboardViews::class)->setStatus($overview, 'inactive', $this->hr))->toThrow(RuntimeException::class, 'stays active');
    Livewire::test(ListDashboards::class)->callTableAction('activate', $view)->callTableAction('sendNow', $view);
    expect($view->fresh()->status)->toBe('active')
        ->and(NotificationDelivery::query()->where('event', DashboardDeliveries::EVENT)->where('user_id', $named->id)->where('channel', 'in_app')->count())->toBe(1);

    $this->get(DashboardResource::getUrl('index'))->assertOk()->assertSee('Departments: Engineering')->assertSee('Add a CXO view');
    $this->get(DashboardResource::getUrl('edit', ['record' => $view]))->assertOk()->assertSee('Shared view');
    $this->get(DashboardViewer::getUrl(['dashboard' => $view->slug]))->assertOk()->assertSee('Applicable on: Departments: Engineering')->assertSee('Payroll figures as of');
    $this->actingAs($named);
    $this->get(DashboardViewer::getUrl(['dashboard' => $view->slug]))->assertOk()->assertSee('Helpdesk — Engineering');
    $this->get(DashboardResource::getUrl('index'))->assertForbidden();
});

it('lists the CXO access setting and screen under Setup → Access rights', function () {
    $catalogue = app(SetupCatalogue::class);
    expect(collect(config('peopleos_setup.modules.access.settings'))->pluck('key')->all())->toContain('analytics.views.email')
        ->and(app(SettingsRepository::class)->get('analytics.views.email'))->toBeTrue()
        ->and(collect($catalogue->links('access', $this->hr))->pluck('label')->all())->toContain('CXO views (dashboard builder)');
});
