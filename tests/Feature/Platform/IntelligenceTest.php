<?php

use App\Domain\Analytics\Models\Report;
use App\Domain\Analytics\Models\ReportRun;
use App\Domain\Analytics\Models\ReportSchedule;
use App\Domain\Analytics\Services\DatasetRegistry;
use App\Domain\Analytics\Services\PlatformAnalytics;
use App\Domain\Analytics\Services\ReportExports;
use App\Domain\Analytics\Services\ReportRunner;
use App\Domain\Analytics\Services\ReportSchedules;
use App\Domain\Analytics\Services\WorkforceMetrics;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\ChangeIntelligence;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Enterprise\Services\WarehouseExport;
use App\Domain\Experience\Services\Employee360;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Lifecycle\Services\Timeline;
use App\Domain\Lifecycle\Support\TimelineCategories;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use App\Filament\Pages\ChangeIntelligencePage;
use App\Filament\Pages\PeopleAnalyticsPage;
use App\Filament\Resources\AuditEvents\Pages\ListAuditEvents;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Resources\Employees\RelationManagers\TimelineRelationManager;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Phase 14.4: Employee 360 orchestration, the typed and gated timeline, Change Intelligence (read-only,
 * organisation-scoped, masked), cross-domain analytics (gated, suppressed, factual), and the analytics
 * leak fixes (gated KPIs, fail-closed fields, scheduled runs as owner, truncation never silent).
 */

beforeEach(function () {
    $this->travelTo('2026-10-03 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->company = Company::factory()->create();
    $this->delhi = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Delhi']);
    $this->mumbai = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Mumbai']);
    $hire = fn (string $first, Location $at, string $joined = '2025-01-01') => app(HireEmployeeAction::class)->handle(['first_name' => $first, 'last_name' => 'Test'], ['joining_date' => $joined], ['company_id' => $this->company->id, 'location_id' => $at->id]);
    $this->priya = $hire('Priya', $this->delhi, '2026-09-20');
    $this->rahul = $hire('Rahul', $this->mumbai, '2026-09-25');
    $this->delhiAuditor = tenantUser($this->tenant, ['audit.view', 'employee.view', 'analytics.reports']);
    app(AccessScopes::class)->assign($this->delhiAuditor, ['location' => [$this->delhi->id]]);
});

it('builds the Employee 360 from each domain only where the viewer may see that domain (invariant 30)', function () {
    $full = collect(app(Employee360::class)->for($this->hr, $this->priya))->pluck('key')->all();
    expect($full)->toContain('identity', 'employment', 'organisation', 'attendance', 'leave', 'payroll', 'performance', 'goals', 'learning', 'skills', 'career',
        'talent', 'succession', 'compensation', 'documents', 'letters', 'service', 'engagement', 'communication', 'exit', 'alumni');

    $viewer = tenantUser($this->tenant, ['employee.view']);
    expect(collect(app(Employee360::class)->for($viewer, $this->priya))->pluck('key')->all())->toBe(['identity', 'employment', 'organisation']);

    $payrollOnly = tenantUser($this->tenant, ['employee.view', 'payroll.view']);
    expect(collect(app(Employee360::class)->for($payrollOnly, $this->priya))->pluck('key')->all())->toBe(['identity', 'employment', 'organisation', 'payroll']);

    // Outside the viewer's organisation scope there is no 360 at all.
    $mumbaiHr = tenantUser($this->tenant, ['*']);
    app(AccessScopes::class)->assign($mumbaiHr, ['location' => [$this->mumbai->id]]);
    expect(app(Employee360::class)->for($mumbaiHr, $this->priya))->toBe([]);

    // Compensation carries the assignment date and a pointer, never an amount.
    $compensation = collect(app(Employee360::class)->for($this->hr, $this->priya))->firstWhere('key', 'compensation');
    expect(array_keys($compensation['facts']))->toBe(['Current assignment since', 'Amounts']);

    $this->get(EmployeeResource::getUrl('view', ['record' => $this->priya]))->assertOk()->assertSee('360 overview')->assertSee('Organisation');
});

it('types every timeline entry and hides categories the viewer may not see', function () {
    $timeline = app(Timeline::class);
    $timeline->record($this->priya, 'position', 'Joined Sales', '2026-09-20');
    $timeline->record($this->priya, 'performance', 'Review finalized', '2026-09-30');
    $timeline->record($this->priya, 'bgv', 'Verification completed', '2026-09-21');
    $timeline->record($this->priya, 'exit', 'Exit initiated', '2026-10-01', 'Personal reasons');
    $entries = fn (string $category) => $this->priya->timelineEntries()->where('category', $category)->get();

    expect(TimelineCategories::kind('position'))->toBe('employment')->and(TimelineCategories::kind('exit'))->toBe('lifecycle')
        ->and(TimelineCategories::kind('service_request'))->toBe('service')->and(TimelineCategories::kind('communication'))->toBe('communication')
        ->and(TimelineCategories::kind('leave'))->toBe('domain');

    $viewer = tenantUser($this->tenant, ['employee.view']);
    expect(TimelineCategories::hiddenFor($viewer, $this->priya))->toContain('performance', 'bgv', 'compensation', 'bank', 'statutory')
        ->and(TimelineCategories::showsDescription($viewer, 'exit'))->toBeFalse()
        ->and(TimelineCategories::hiddenFor($this->hr, $this->priya))->toBe([]);

    $this->actingAs($viewer);
    Livewire::test(TimelineRelationManager::class, ['ownerRecord' => $this->priya, 'pageClass' => ViewEmployee::class])
        ->assertCanSeeTableRecords($entries('position')->merge($entries('exit')))
        ->assertCanNotSeeTableRecords($entries('performance')->merge($entries('bgv')))
        ->assertDontSee('Personal reasons')
        ->filterTable('kind', 'lifecycle')->assertCanSeeTableRecords($entries('exit'))->assertCanNotSeeTableRecords($entries('position'));

    $this->actingAs($this->hr);
    Livewire::test(TimelineRelationManager::class, ['ownerRecord' => $this->priya, 'pageClass' => ViewEmployee::class])
        ->assertCanSeeTableRecords($entries('performance'))->assertSee('Personal reasons');
});

it('answers change questions read-only, inside the viewer organisation scope, with classified values masked', function () {
    $this->priya->refresh()->update(['work_email' => 'priya@new.test']);
    $this->rahul->refresh()->update(['work_email' => 'rahul@new.test']);
    $bank = EmployeeBankAccount::create(['employee_id' => $this->priya->id, 'account_holder_name' => 'Priya', 'bank_name' => 'HDFC', 'account_number' => '1234567890', 'ifsc' => 'HDFC0000001', 'is_primary' => true]);
    $ci = app(ChangeIntelligence::class);
    $noAudit = tenantUser($this->tenant, ['employee.view']);
    $before = AuditEvent::query()->count();

    $mine = fn ($user, array $filters = []) => $ci->query($user, $filters)->get();
    $onEmployee = fn ($events, Employee $e) => $events->contains(fn ($ev) => $ev->entity_type === Employee::class && (int) $ev->entity_id === $e->id);

    // Unscoped: everything; filtered by employee: that employee's records only (including linked rows).
    expect($onEmployee($mine($this->hr), $this->rahul))->toBeTrue();
    $priyaOnly = $mine($this->hr, ['employee_id' => $this->priya->id]);
    expect($onEmployee($priyaOnly, $this->priya))->toBeTrue()->and($onEmployee($priyaOnly, $this->rahul))->toBeFalse()
        ->and($priyaOnly->contains(fn ($ev) => $ev->entity_type === EmployeeBankAccount::class && (int) $ev->entity_id === $bank->id))->toBeTrue();

    // Organisation scope: a Delhi auditor never sees Mumbai changes, even when asking for them.
    expect($onEmployee($mine($this->delhiAuditor), $this->priya))->toBeTrue()
        ->and($onEmployee($mine($this->delhiAuditor), $this->rahul))->toBeFalse()
        ->and($mine($this->delhiAuditor, ['employee_id' => $this->rahul->id]))->toHaveCount(0);

    // Masking: classified records show no values without employee.sensitive.view.
    $bankEvent = AuditEvent::query()->where('entity_type', EmployeeBankAccount::class)->where('entity_id', $bank->id)->firstOrFail();
    $masked = $ci->describe($bankEvent, $this->delhiAuditor);
    expect(collect($masked['changes'])->every(fn ($c) => $c['masked']))->toBeTrue()
        ->and(json_encode($masked))->not->toContain('HDFC0000001')->not->toContain('1234567890');
    expect(json_encode($ci->describe($bankEvent, $this->hr)))->toContain('HDFC0000001')->not->toContain('1234567890');

    // Without audit.view there is no change history at all; reading never writes audit rows.
    expect(fn () => $ci->query($noAudit))->toThrow(RuntimeException::class);
    expect(AuditEvent::query()->count())->toBe($before);

    // The generic audit list applies the same organisation scope; the page renders for one employee.
    $this->actingAs($this->delhiAuditor);
    $rahulEvents = AuditEvent::query()->where('entity_type', Employee::class)->where('entity_id', $this->rahul->id)->get();
    $priyaEvents = AuditEvent::query()->where('entity_type', Employee::class)->where('entity_id', $this->priya->id)->get();
    Livewire::test(ListAuditEvents::class)->assertCanSeeTableRecords($priyaEvents)->assertCanNotSeeTableRecords($rahulEvents);
    $this->get(ChangeIntelligencePage::getUrl(['employee' => $this->priya->id]))->assertOk()->assertSee('Change intelligence');
});

it('gates protected dashboard KPIs, fails closed on fields without a user, and suppresses small populations', function () {
    $metrics = app(WorkforceMetrics::class);
    $viewer = tenantUser($this->tenant, ['analytics.view']);
    expect($metrics->metric('people_cost', $viewer))->toMatchArray(['value' => null, 'restricted' => true])
        ->and($metrics->metric('open_grievances', $viewer)['restricted'] ?? false)->toBeTrue()
        ->and($metrics->metric('headcount', $viewer)['value'])->toBe(2)
        ->and($metrics->metric('people_cost', $this->hr))->not->toHaveKey('restricted')
        ->and($metrics->metric('women_share', $this->hr)['value'])->toBeNull()
        ->and($metrics->metric('women_share', $this->hr)['hint'])->toContain('Suppressed');

    $employees = app(DatasetRegistry::class)->get('employees');
    expect($employees->fieldsFor(null))->not->toHaveKey('ctc_annual')
        ->and($employees->fieldsFor($this->hr))->toHaveKey('ctc_annual');
});

it('runs scheduled reports as the owner and never silently truncates', function () {
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $report = Report::create(['name' => 'People list', 'dataset' => 'employees', 'definition' => ['fields' => ['employee_code', 'ctc_annual']], 'is_shared' => false, 'owner_id' => $this->delhiAuditor->id]);
    $schedule = ReportSchedule::create(['report_id' => $report->id, 'frequency' => 'daily', 'time' => '07:00', 'recipient_user_ids' => []]);

    $this->travelTo('2026-10-04 07:30:00');
    expect(app(ReportSchedules::class)->runDue())->toBe(1);
    $run = ReportRun::query()->where('report_schedule_id', $schedule->id)->sole();
    $csv = app(ReportExports::class)->contents($run);
    // The owner's organisation scope and field rights apply: Delhi only, and no compensation column.
    expect($csv)->toContain($this->priya->employee_code)->not->toContain($this->rahul->employee_code)->not->toContain('Annual CTC');
    // A second pass in the same slot does not export again (the slot is claimed).
    expect(app(ReportSchedules::class)->runDue())->toBe(0);

    // An inactive owner means no run with anyone else's rights.
    $this->delhiAuditor->forceFill(['status' => UserStatus::Suspended])->save();
    $this->travelTo('2026-10-05 07:30:00');
    app(ReportSchedules::class)->runDue();
    expect(ReportRun::query()->where('report_schedule_id', $schedule->id)->latest('id')->first())->status->toBe('failed');

    // Truncation: the cap applies after the filters, and a capped run says so.
    config(['peopleos.analytics.max_rows' => 1]);
    $result = app(ReportRunner::class)->execute('employees', ['fields' => ['employee_code']], $this->hr);
    expect($result->truncated)->toBeTrue()->and($result->rows)->toHaveCount(1);
    $filtered = app(ReportRunner::class)->execute('employees', ['fields' => ['employee_code'], 'filters' => [['field' => 'employee_code', 'operator' => 'equals', 'value' => $this->rahul->employee_code]]], $this->hr);
    expect($filtered->truncated)->toBeFalse()->and($filtered->rows[0]['employee_code'])->toBe($this->rahul->employee_code);

    // The warehouse feed carries no sensitive fields and records truncation in its manifest.
    app(WarehouseExport::class)->run($this->hr, ['employees']);
    $folder = 'warehouse/'.$this->tenant->slug.'/'.now()->format('Y-m-d');
    expect(Storage::disk('local')->get("{$folder}/employees.jsonl"))->not->toContain('ctc_annual')
        ->and(json_decode(Storage::disk('local')->get("{$folder}/manifest.json"), true)['truncated'])->toBe(['employees']);
});

it('shows cross-domain analytics per domain permission, suppressed and factual only', function () {
    $analytics = app(PlatformAnalytics::class);
    $viewer = tenantUser($this->tenant, ['analytics.view']);
    expect(collect($analytics->overview($viewer))->pluck('key')->all())->toBe(['people']);
    $people = collect($analytics->overview($viewer))->firstWhere('key', 'people')['facts'];
    expect($people['Headcount on 2026-10-03'])->toBe(2)->and($people['Joiners (30 days)'])->toBe('fewer than 5');

    $all = $analytics->overview($this->hr);
    expect(collect($all)->pluck('key')->all())->toBe(array_keys(PlatformAnalytics::AREAS))
        ->and(strtolower(json_encode($all)))->not->toMatch('/risk|score|predict|flight|likel/');

    $this->get(PeopleAnalyticsPage::getUrl())->assertOk()->assertSee('People analytics')->assertSee('Engagement &amp; communication', false);
    $this->actingAs(tenantUser($this->tenant, ['employee.view']));
    $this->get(PeopleAnalyticsPage::getUrl())->assertForbidden();
});

it('renders the new Employee 360 tabs for a full HR viewer', function () {
    foreach (['GoalsRelationManager', 'PayslipsRelationManager', 'LettersRelationManager', 'ExitRelationManager', 'CommunicationsRelationManager'] as $manager) {
        Livewire::test('App\\Filament\\Resources\\Employees\\RelationManagers\\'.$manager, ['ownerRecord' => $this->priya, 'pageClass' => ViewEmployee::class])->assertOk();
    }
});
