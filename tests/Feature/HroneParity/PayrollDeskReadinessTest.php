<?php

use App\Domain\Analytics\Models\Report;
use App\Domain\Attendance\Models\AttendanceArrear;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compliance\Imports\StatutoryOverrideImports;
use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Models\ComplianceRuleNotice;
use App\Domain\Compliance\Services\ComplianceRules;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Exit\Services\Exits;
use App\Domain\Experience\Services\ExperienceNavigation;
use App\Domain\Identity\Models\UserAccessScope;
use App\Domain\Identity\Services\PermissionRegistry;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollEntryLine;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Services\PayrollReadiness;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Domain\Payroll\Services\SalaryHolds;
use App\Domain\Payroll\Services\VariablePay;
use App\Filament\Pages\PayrollControlRoom;
use App\Filament\Pages\PayrollDesk;
use App\Filament\Resources\AttendanceArrears\Pages\ListAttendanceArrears;
use App\Filament\Resources\CompensationChanges\Pages\ManageCompensationChanges;
use App\Filament\Resources\ExitCases\Pages\ListExitCases;
use App\Filament\Resources\Letters\Pages\ListLetters;
use App\Filament\Resources\PayrollRuns\Pages\ListPayrollRuns;
use App\Filament\Resources\PayrollRuns\Pages\ViewPayrollRun;
use App\Filament\Resources\PayrollRuns\RelationManagers\EntriesRelationManager;
use App\Filament\Resources\PayrollUploads\PayrollUploadResource;
use App\Filament\Resources\SalaryHolds\Pages\ListSalaryHolds;
use App\Filament\Resources\SalaryImports\SalaryImportResource;
use App\Filament\Resources\VariablePayRequests\Pages\ListVariablePayRequests;
use App\Filament\Resources\VariablePayRequests\VariablePayRequestResource;
use App\Filament\Support\PayrollRegisters;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/*
| HROne parity PO-1 (matrix 25 §25.1, §25.13 #4: the Payroll panel as a desk in HROne's order, the payroll registers'
| pay-group / employee / date filters, views, reports, uploads and actions menu) and PO-3 (§25.6.2, §25.6.1 #8: the
| pre-run readiness panel on the payroll control room — read-only counts, lists and an audited CSV; statutory thresholds
| only from verified rules).
*/

beforeEach(function () {
    $this->travelTo('2026-09-25 10:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->approver = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    Filament::getPanel('admin')->boot();
    $this->company = payrollCompany();
    $this->standard = SalaryStructure::query()->where('code', 'STANDARD')->firstOrFail();

    // Paid in September: a (above the ESI ceiling, every identifier on file) and b (ESI covered, PAN only).
    $this->a = salariedEmployee(600000);
    $this->b = salariedEmployee(240000);
    EmployeeStatutoryDetail::query()->updateOrCreate(['employee_id' => $this->a->id], ['pan' => 'ABCDE1234F', 'aadhaar_reference' => 'AADH-9911', 'uan' => '100200300400', 'esic_number' => '3100123456', 'pf_applicable' => true, 'esic_applicable' => true]);
    EmployeeStatutoryDetail::query()->updateOrCreate(['employee_id' => $this->b->id], ['pan' => 'PQRSX9876Z', 'aadhaar_reference' => null, 'uan' => null, 'esic_number' => null, 'pf_applicable' => true, 'esic_applicable' => true]);
    foreach ([$this->a, $this->b] as $e) {
        EmployeeBankAccount::create(['employee_id' => $e->id, 'account_holder_name' => 'X', 'bank_name' => 'HDFC', 'account_number' => '1234567890', 'ifsc' => 'HDFC0000001', 'is_primary' => true]);
    }
    $runs = app(PayrollRuns::class);
    $run = $runs->calculate($runs->open($this->company, 2026, 9, $this->hr), $this->hr);
    $run = $runs->approve($runs->validate($run), $this->approver);
    $this->september = $runs->finalize($run, $this->approver);

    // Hired after September was paid: c (no salary, no statutory details), e (serving notice), g (breakup above CTC).
    $this->travelTo('2026-10-15 10:00:00');
    $this->c = employeeWithUser();
    forceLifecycle($this->c, LifecycleState::Active);
    EmployeeStatutoryDetail::query()->where('employee_id', $this->c->id)->get()->each->delete();
    $this->e = salariedEmployee(360000);
    forceLifecycle($this->e, LifecycleState::NoticePeriod);
    $this->g = salariedEmployee(240000, ['task.view'], '2025-01-01', ['CONV' => 30000]);
    // f left on 5 October: in the pay month's population, not an active employee.
    $this->f = salariedEmployee(300000);
    forceLifecycle($this->f, LifecycleState::Exited, ['exit_date' => '2026-10-05']);

    for ($day = 1; $day <= 14; $day++) {
        AttendanceRecord::create(['employee_id' => $this->a->id, 'date' => sprintf('2026-10-%02d', $day), 'status' => 'present', 'processed_at' => now()]);
    }
});

/** @return list<string> employee codes listed by a readiness check */
function po13Codes(array $result, string $check): array
{
    return array_values(array_column($result['checks'][$check]['rows'], 'employee_code'));
}

function po13Rule(string $code, ?string $state, array $parameters, string $from = '2025-01-01', int $version = 1): ComplianceRule
{
    $rule = ComplianceRule::query()->create(['jurisdiction' => 'IN', 'code' => $code, 'state' => $state, 'name' => $code.' test rule'.($state ? ' '.$state : ''), 'version' => $version, 'effective_from' => $from, 'parameters' => $parameters, 'status' => ComplianceRule::ACTIVE]);
    DB::table('compliance_rules')->where('id', $rule->id)->update(['verification_status' => ComplianceRule::VERIFIED]);
    app(ComplianceRules::class)->forget();

    return $rule->refresh();
}

// ---- PO-3: the readiness checks ------------------------------------------------------------------------------------

it('counts each pre-run check for the current pay month over the run population, active employees only by default', function () {
    $result = app(PayrollReadiness::class)->evaluate();
    $codes = fn (string $check) => po13Codes($result, $check);
    [$a, $b, $c, $e, $f, $g] = [$this->a->employee_code, $this->b->employee_code, $this->c->employee_code, $this->e->employee_code, $this->f->employee_code, $this->g->employee_code];

    expect(array_keys($result['checks']))->toBe(array_keys(PayrollReadiness::CHECKS))
        ->and($result['months'][0]['label'])->toBe('Oct 2026')
        // Last month's population without an entry in September's finalized run.
        ->and($codes('salary_unprocessed'))->toContain($c, $e, $g)->not->toContain($a)->not->toContain($b)->not->toContain($f)
        // 1–14 October without processed attendance (a has every day).
        ->and($codes('attendance_unprocessed'))->toContain($b, $c)->not->toContain($a)
        ->and(collect($result['checks']['attendance_unprocessed']['rows'])->firstWhere('employee_code', $b)['days'])->toBe(14)
        ->and($codes('statutory_details_missing'))->toContain($c)->not->toContain($a)->not->toContain($b)
        ->and($codes('structure_missing'))->toBe([$c])
        ->and($codes('pan_missing'))->toContain($c)->not->toContain($a)->not->toContain($b)
        ->and($codes('aadhaar_missing'))->toContain($b, $c)->not->toContain($a)
        ->and($codes('uan_missing'))->toContain($b, $c)->not->toContain($a)
        // ESI was deducted from b in September and b has no IP number; a is above the ceiling (no ESI), c was never paid.
        ->and($codes('esi_ip_missing'))->toBe([$b])
        ->and($codes('unbalanced_ctc'))->toBe([$g])
        ->and(collect($result['checks']['unbalanced_ctc']['rows'])->first()['detail'])->toBe('The breakup comes to more than the CTC')
        ->and($codes('special_states'))->toBe([$e])
        ->and(collect($result['checks']['special_states']['rows'])->first()['detail'])->toBe('Serving notice');

    // Statutory thresholds: no verified rule, so the checks say so and do not run (no fallback number).
    foreach (['wage_share', 'minimum_wage', 'esi_ceiling'] as $check) {
        expect($result['checks'][$check]['state'])->toBe('no_rule')->and($result['checks'][$check]['count'])->toBeNull()->and($result['checks'][$check]['rows'])->toBe([]);
    }
    expect(implode(' ', $result['checks']['esi_ceiling']['notes']))->toContain('No verified ESI rule')
        ->and(implode(' ', $result['checks']['wage_share']['notes']))->toContain('No verified wage rule');
    // The statutory set-up check lists only rules that are not verified (0 of the pack is verified).
    expect(collect($result['checks']['statutory_setup']['rows'])->pluck('status')->all())->not->toContain('Verified');

    // Everyone in the pay month's population: the exited employee appears, with their last working day.
    $everyone = app(PayrollReadiness::class)->evaluate(null, false);
    expect(po13Codes($everyone, 'special_states'))->toContain($e, $f)
        ->and(collect($everyone['checks']['special_states']['rows'])->firstWhere('employee_code', $f)['detail'])->toBe('Exited · Last working day 5 Oct 2026')
        ->and(po13Codes($everyone, 'salary_unprocessed'))->toContain($f);
});

it('runs the minimum wage, wage share and ESI ceiling checks only with a verified rule, reading every figure from it', function () {
    // A verified minimum wage for another state does not check employees of Karnataka.
    po13Rule('MIN_WAGE', 'MH', ['monthly_minimum' => 25000]);
    $result = app(PayrollReadiness::class)->evaluate();
    expect($result['checks']['minimum_wage']['state'])->toBe('no_rule')
        ->and(implode(' ', $result['checks']['minimum_wage']['notes']))->toContain('not checked: no verified minimum wage');

    // A verified rule without the figure the check needs is no rule either.
    po13Rule('WAGES', null, ['definition' => 'basic_da']);
    expect(app(PayrollReadiness::class)->evaluate()['checks']['wage_share']['state'])->toBe('no_rule');

    DB::table('compliance_rules')->where('code', 'ESI')->update(['verification_status' => ComplianceRule::VERIFIED]);
    app(ComplianceRules::class)->forget();
    $wages = po13Rule('WAGES', null, ['min_wage_share' => 50], '2026-01-01', 2);
    $ka = po13Rule('MIN_WAGE', 'KA', ['monthly_minimum' => 25000]);
    $esi = app(ComplianceRules::class)->resolve('ESI', '2026-10-31');

    $result = app(PayrollReadiness::class)->evaluate(null, true, true);
    $row = fn (string $check, Employee $employee) => collect($result['checks'][$check]['rows'])->firstWhere('employee_code', $employee->employee_code);

    // a: CTC 6,00,000 — basic 20,000 a month (40% of CTC) and about 42% of gross, gross above the ESI ceiling.
    expect($result['checks']['esi_ceiling']['state'])->toBe('attention')
        ->and(po13Codes($result, 'esi_ceiling'))->toContain($this->a->employee_code)->not->toContain($this->b->employee_code)
        ->and($row('esi_ceiling', $this->a)['threshold'])->toBe((float) $esi->param('wage_ceiling'))
        ->and($row('esi_ceiling', $this->a)['wages'])->toBeGreaterThan((float) $esi->param('wage_ceiling'))
        ->and($row('wage_share', $this->a)['threshold'])->toBe((float) $wages->param('min_wage_share'))
        ->and($row('wage_share', $this->a)['wage_share'])->toBeGreaterThan(40.0)->toBeLessThan(50.0)
        ->and(po13Codes($result, 'wage_share'))->toContain($this->b->employee_code)
        ->and($row('minimum_wage', $this->a))->toMatchArray(['wages' => 20000.0, 'threshold' => (float) $ka->param('monthly_minimum'), 'state' => 'KA']);

    // An open regulatory notice on the ESI rule takes it out of use again.
    ComplianceRuleNotice::query()->create(['jurisdiction' => 'IN', 'code' => 'ESI', 'state' => null, 'affects_versions' => [(int) $esi->version], 'effective_date' => '2026-10-01',
        'title' => 'Ceiling revision', 'summary' => 'Proposed change', 'references' => [], 'retrieved_at' => '2026-10-01', 'checksum' => str_repeat('a', 64), 'status' => ComplianceRuleNotice::OPEN]);
    app(ComplianceRules::class)->forget();
    expect(app(PayrollReadiness::class)->evaluate()['checks']['esi_ceiling']['state'])->toBe('no_rule');
});

it('shows amounts only to readers allowed compensation and never an identifier', function () {
    DB::table('compliance_rules')->where('code', 'ESI')->update(['verification_status' => ComplianceRule::VERIFIED]);
    app(ComplianceRules::class)->forget();
    $plain = app(PayrollReadiness::class)->evaluate(null, true, false);
    $amounts = app(PayrollReadiness::class)->evaluate(null, true, true);

    expect($plain['checks']['unbalanced_ctc']['columns'])->not->toHaveKey('breakup')->not->toHaveKey('ctc')
        ->and($plain['checks']['unbalanced_ctc']['rows'][0])->not->toHaveKey('breakup')
        ->and($plain['checks']['esi_ceiling']['columns'])->not->toHaveKey('wages')->toHaveKey('threshold')
        ->and($amounts['checks']['unbalanced_ctc']['columns'])->toHaveKey('breakup')
        ->and($amounts['checks']['unbalanced_ctc']['rows'][0]['ctc'])->toBe(20000.0);

    $everything = json_encode([$plain, $amounts]);
    foreach (['ABCDE1234F', 'PQRSX9876Z', 'AADH-9911', '100200300400', '3100123456'] as $identifier) {
        expect($everything)->not->toContain($identifier);
    }
});

it('shows the panel on the control room, opens a list and exports it as an audited CSV without identifiers', function () {
    $page = Livewire::test(PayrollControlRoom::class)
        ->assertSee('Before you run payroll')->assertDontSee('PAN missing')
        ->call('loadReadiness')
        ->assertSee('PAN missing')->assertSee('No verified rule')->assertSee('Employees in special states')
        ->call('openReadinessCheck', 'pan_missing')->assertSet('readinessCheck', 'pan_missing')
        ->assertSee($this->c->employee_code)
        ->call('openReadinessCheck', 'not_a_check')->assertSet('readinessCheck', null)
        ->call('openReadinessCheck', 'special_states')->assertSee($this->e->employee_code)->assertDontSee($this->f->employee_code)
        ->set('readinessActiveOnly', false)->assertSee($this->f->employee_code);
    $page->call('downloadReadiness', 'pan_missing')->assertFileDownloaded('payroll-readiness-pan-missing-2026-10-15.csv');

    $event = AuditEvent::query()->where('action', 'EXPORT')->where('module', 'payroll')->latest('id')->firstOrFail();
    expect($event->metadata)->toMatchArray(['export' => 'payroll_readiness', 'check' => 'pan_missing', 'active_only' => false])
        ->and($event->actor_id)->toBe($this->hr->id);

    $csv = app(PayrollReadiness::class)->csv('aadhaar_missing', $this->hr);
    expect($csv)->toContain('Employee code', $this->b->employee_code, $this->c->employee_code)
        ->not->toContain('PQRSX9876Z')->not->toContain('ABCDE1234F');
    expect(fn () => app(PayrollReadiness::class)->csv('nope', $this->hr))->toThrow(RuntimeException::class, 'Unknown readiness check');
});

it('never writes payroll and never creates the pay month it looks at', function () {
    $counts = fn () => [PayrollRun::query()->count(), PayrollEntry::query()->count(), PayrollEntryLine::query()->count(), PayrollPeriod::query()->count(), PayrollRun::query()->max('updated_at')];
    $before = $counts();
    app(PayrollReadiness::class)->evaluate(null, false, true);
    app(PayrollReadiness::class)->csv('unbalanced_ctc', $this->hr);
    Livewire::test(PayrollControlRoom::class)->call('loadReadiness')->call('openReadinessCheck', 'structure_missing');

    expect($counts())->toBe($before)
        ->and(PayrollPeriod::query()->where('year', 2026)->where('month', 10)->exists())->toBeFalse()
        ->and($this->september->refresh()->status)->toBe('finalized');
});

it('keeps the readiness inside the reader\'s access scope and the tenant (fail closed)', function () {
    $department = Department::factory()->create();
    EmployeePosition::query()->where('employee_id', $this->b->id)->update(['department_id' => $department->id]);
    $scoped = tenantUser($this->tenant, ['payroll.view', 'payroll.calculate']);
    UserAccessScope::query()->create(['user_id' => $scoped->id, 'dimension' => 'department', 'scope_id' => $department->id]);
    $this->actingAs($scoped);

    $result = app(PayrollReadiness::class)->evaluate(null, true);
    $listed = collect($result['checks'])->except('statutory_setup')->flatMap(fn (array $c) => array_column($c['rows'], 'employee_code'))->unique()->values()->all();
    expect($listed)->toBe([$this->b->employee_code]);

    // Another tenant's employees and companies never appear; naming its company gives nothing.
    $this->actingAs($this->hr);
    $other = provisionTenant('Other');
    actAsTenant($other);
    $stranger = salariedEmployee(300000);
    $otherCompany = Company::query()->first();
    actAsTenant($this->tenant);
    $result = app(PayrollReadiness::class)->evaluate();
    $all = collect($result['checks'])->flatMap(fn (array $c) => array_column($c['rows'], 'employee_id'))->all();
    expect($all)->not->toContain($stranger->id);
    $foreign = app(PayrollReadiness::class)->evaluate($otherCompany->id);
    expect($foreign['months'])->toBe([])
        ->and(collect($foreign['checks'])->except('statutory_setup')->flatMap(fn (array $c) => $c['rows'])->all())->toBe([]);
    Livewire::test(PayrollControlRoom::class)->set('readinessCompany', (string) $otherCompany->id)->call('loadReadiness')->assertDontSee($stranger->employee_code);
});

it('opens the control room and its readiness for payroll.view only', function () {
    $this->actingAs(tenantUser($this->tenant, ['employee.view', 'compensation.view']));
    expect(PayrollControlRoom::canAccess())->toBeFalse();
    $this->get(PayrollControlRoom::getUrl())->assertForbidden();

    $auditor = tenantUser($this->tenant, ['payroll.view']);
    $this->actingAs($auditor);
    $this->get(PayrollControlRoom::getUrl())->assertOk()->assertSee('Before you run payroll');
    // Without compensation.view the lists carry no amounts.
    $page = Livewire::test(PayrollControlRoom::class)->call('loadReadiness')->call('openReadinessCheck', 'unbalanced_ctc');
    expect($page->instance()->readiness()['checks']['unbalanced_ctc']['columns'])->not->toHaveKey('breakup');
});

// ---- PO-1: the payroll desk ------------------------------------------------------------------------------------------

it('opens the payroll desk for payroll operators only, in HROne\'s panel order, with its views and counts', function () {
    $bonus = SalaryComponent::query()->where('code', 'BONUS')->firstOrFail();
    app(VariablePay::class)->request($this->a, $bonus, 5000, '2026-10', 'Quarter target');
    app(SalaryHolds::class)->hold($this->b, '2026-10', 'Missing documents');

    $this->get(PayrollDesk::getUrl())->assertOk()
        ->assertSee('Salary structure requests')->assertSee('Variable pay cancellations')->assertSee('Settlement requests')
        ->assertSee(VariablePayRequestResource::getUrl('index', ['view' => 'cancellations']), false);
    $sections = collect((new PayrollDesk)->deskSections());
    $item = fn (string $section, string $label) => collect($sections->firstWhere('label', $section)['items'])->firstWhere('label', $label);
    expect($sections->pluck('label')->all())->toBe(['Salary structure', 'Tax & benefit', 'Variable pay', 'Loan', 'Payroll processing', 'Full and final settlement', 'Payroll letters', 'Reports', 'Payroll setup'])
        ->and($item('Variable pay', 'Variable pay requests')['count'])->toBe(1)
        ->and($item('Payroll processing', 'Salary holds')['count'])->toBe(1)
        ->and($item('Payroll processing', 'Payroll runs')['count'])->toBe(0)
        ->and(ExperienceNavigation::MODULE_AREA)->toHaveKey('PayrollDesk')
        ->and(ListExitCases::presetViews())->toHaveKey('settlement')->toHaveKey('settled')
        ->and(ListLetters::presetViews()['increment']['filters'])->toBe(['type' => ['value' => 'increment']]);

    // Auditors (read-only) and employees run no payroll desk.
    $this->actingAs(tenantUser($this->tenant, ['payroll.view']));
    expect(PayrollDesk::canAccess())->toBeFalse();
    $this->get(PayrollDesk::getUrl())->assertForbidden();
    $this->actingAs(tenantUser($this->tenant, ['payroll.payslip', 'payroll.request']));
    expect(PayrollDesk::canAccess())->toBeFalse();

    // Someone who calculates payroll gets the desk with only what they can open.
    $this->actingAs(tenantUser($this->tenant, ['payroll.view', 'payroll.calculate']));
    expect(PayrollDesk::canAccess())->toBeTrue();
    $labels = collect((new PayrollDesk)->deskSections())->flatMap(fn (array $s) => array_column($s['items'], 'label'));
    expect($labels)->toContain('Run payroll')->toContain('Payroll runs')->not->toContain('Salary holds')->not->toContain('Variable pay requests');
    $actions = collect(PayrollRegisters::actionsMenu()->getActions())->filter(fn (Action $a) => $a->isVisible())->map(fn (Action $a) => $a->getName())->values()->all();
    expect($actions)->toContain('posPayRun')->not->toContain('posPayHold')->not->toContain('posPayLoan');
});

it('filters payroll registers by pay group, pay month, employee and dates on the payroll side only', function () {
    $intern = SalaryStructure::query()->create(['name' => 'Interns', 'code' => 'INTERN', 'status' => 'active']);
    $holds = app(SalaryHolds::class);
    $heldA = $holds->hold($this->a, '2026-10', 'Documents');
    $heldC = $holds->hold($this->c, '2026-10', 'Documents');
    $heldB = $holds->hold($this->b, '2026-09', 'Documents');

    Livewire::test(ListSalaryHolds::class)
        ->assertCanSeeTableRecords([$heldA, $heldC, $heldB])
        ->filterTable('salary_structure', [$this->standard->id])->assertCanSeeTableRecords([$heldA, $heldB])->assertCanNotSeeTableRecords([$heldC])
        ->filterTable('salary_structure', [$intern->id])->assertCanNotSeeTableRecords([$heldA, $heldB, $heldC])
        ->resetTableFilters()->filterTable('pay_month_filter', '2026-09')->assertCanSeeTableRecords([$heldB])->assertCanNotSeeTableRecords([$heldA, $heldC]);

    $bonus = SalaryComponent::query()->where('code', 'BONUS')->firstOrFail();
    $pay = app(VariablePay::class);
    $pending = $pay->request($this->a, $bonus, 5000, '2026-10', 'Target');
    $cancelled = $pay->cancel($pay->request($this->b, $bonus, 3000, '2026-10', 'Target'), 'Duplicate');
    $search = Livewire::test(ListVariablePayRequests::class)->searchTable($this->a->employee_code);
    $search->assertCanSeeTableRecords([$pending]);
    $search->assertCanNotSeeTableRecords([$cancelled]);
    $codes = Livewire::test(ListVariablePayRequests::class)->filterTable('employee_codes', ['codes' => $this->b->employee_code]);
    $codes->assertCanSeeTableRecords([$cancelled]);
    $codes->assertCanNotSeeTableRecords([$pending]);
    $view = Livewire::withQueryParams(['view' => 'cancellations'])->test(ListVariablePayRequests::class);
    $view->assertCanSeeTableRecords([$cancelled]);
    $view->assertCanNotSeeTableRecords([$pending]);
    Livewire::withQueryParams([]);

    // The register's report carries its filters as the variable pay dataset shows them.
    Livewire::test(ListVariablePayRequests::class)->filterTable('status', ['pending'])->filterTable('pay_month_filter', '2026-10')
        ->callAction('registerReport', ['template' => 'variable_requests', 'with_filters' => true])->assertHasNoActionErrors()->assertRedirect();
    $filters = collect(Report::query()->latest('id')->firstOrFail()->definition['filters']);
    expect($filters->firstWhere('field', 'status'))->toMatchArray(['operator' => 'in', 'value' => 'pending'])
        ->and($filters->firstWhere('field', 'pay_month'))->toMatchArray(['operator' => 'equals', 'value' => '2026-10']);

    // An employee's own variable pay list looks as it did.
    $this->actingAs($this->a->user);
    $this->a->user->roles()->first()?->permissions()->syncWithoutDetaching(app(PermissionRegistry::class)->idsMatching(['payroll.request']));
    $this->actingAs($this->a->user->fresh());
    Livewire::test(ListVariablePayRequests::class)
        ->assertCanSeeTableRecords([$pending])->assertCanNotSeeTableRecords([$cancelled])
        ->assertTableFilterHidden('salary_structure')->assertTableFilterHidden('pay_month_filter')->assertTableFilterHidden('organisation')->assertTableFilterHidden('request_dates')
        ->assertActionDoesNotExist('registerReport')->assertActionDoesNotExist('posSaveView')->assertActionVisible('request');
});

it('filters run entries by pay group on the run\'s period end and leaves out pending resignations', function () {
    $entryA = $this->september->entries()->where('employee_id', $this->a->id)->firstOrFail();
    $entryB = $this->september->entries()->where('employee_id', $this->b->id)->firstOrFail();
    $intern = SalaryStructure::query()->create(['name' => 'Interns', 'code' => 'INTERN', 'status' => 'active']);
    app(Exits::class)->initiate($this->b, 'resignation', 'Moving');

    $relation = fn () => Livewire::test(EntriesRelationManager::class, ['ownerRecord' => $this->september, 'pageClass' => ViewPayrollRun::class]);
    $relation()->assertCanSeeTableRecords([$entryA, $entryB])
        ->filterTable('salary_structure', [$this->standard->id])->assertCanSeeTableRecords([$entryA, $entryB])
        ->filterTable('salary_structure', [$intern->id])->assertCanNotSeeTableRecords([$entryA, $entryB]);
    $relation()->filterTable('exclude_pending_resignation', true)->assertCanSeeTableRecords([$entryA])->assertCanNotSeeTableRecords([$entryB]);
    $relation()->filterTable('employee_codes', ['codes' => $this->b->employee_code])->assertCanSeeTableRecords([$entryB])->assertCanNotSeeTableRecords([$entryA]);
});

it('filters attendance arrears by arrear month and arrear-day bands, and compensation changes by revision month', function () {
    $arrear = fn (Employee $e, string $date, float $delta) => AttendanceArrear::query()->create(['employee_id' => $e->id, 'date' => $date, 'status_before' => 'absent', 'status_after' => 'present', 'paid_before' => 0, 'paid_after' => 1, 'delta_days' => $delta, 'status' => 'pending']);
    $a1 = $arrear($this->a, '2026-09-03', 1);
    $a2 = $arrear($this->a, '2026-09-04', 1);
    $b1 = $arrear($this->b, '2026-09-10', -1);
    $c1 = $arrear($this->c, '2026-08-20', 1);

    Livewire::test(ListAttendanceArrears::class)
        ->filterTable('arrear_month', ['month' => '2026-09'])->assertCanSeeTableRecords([$a1, $a2, $b1])->assertCanNotSeeTableRecords([$c1])
        ->filterTable('arrear_month', ['month' => '2026-09', 'days' => 'negative'])->assertCanSeeTableRecords([$b1])->assertCanNotSeeTableRecords([$a1, $a2, $c1])
        ->filterTable('arrear_month', ['month' => '2026-09', 'days' => 'one_to_five'])->assertCanSeeTableRecords([$a1, $a2])->assertCanNotSeeTableRecords([$b1, $c1])
        ->filterTable('arrear_month', ['month' => null, 'days' => 'one_to_five'])->assertCanSeeTableRecords([$a1, $a2, $c1]);

    // A revision taking effect next month (September is finalized; payroll history is never rewritten).
    compensate($this->b, 300000, '2026-11-01', ['CONV' => 1600], 'revision', 'Annual increment');
    $revision = CompensationChange::query()->where('employee_id', $this->b->id)->where('change_type', 'revision')->firstOrFail();
    $hire = CompensationChange::query()->where('employee_id', $this->b->id)->where('change_type', 'hire')->firstOrFail();
    Livewire::test(ManageCompensationChanges::class)
        ->filterTable('revision_month', '2026-11')->assertCanSeeTableRecords([$revision])->assertCanNotSeeTableRecords([$hire]);
    Livewire::withQueryParams(['view' => 'effective'])->test(ManageCompensationChanges::class)->assertCanSeeTableRecords([$hire])->assertCanNotSeeTableRecords([$revision]);
});

it('links registers to their uploads, reports and payroll actions only where the person may use them', function () {
    $urls = collect(PayrollRegisters::uploads(['salary', 'stat_overrides'])->getActions())->mapWithKeys(fn (Action $a) => [$a->getName() => $a->isVisible() ? $a->getUrl() : null]);
    expect($urls['payrollUpload_salary'])->toBe(SalaryImportResource::getUrl('index', ['action' => 'create']))
        ->and($urls['payrollUpload_stat_overrides'])->toBe(PayrollUploadResource::getUrl('index', ['action' => 'create', 'actionArguments' => ['type' => StatutoryOverrideImports::TYPE]]));
    Livewire::test(ListPayrollRuns::class)->assertActionVisible('registerReport')->assertActionVisible('openRun');
    Livewire::test(ManageCompensationChanges::class)->assertActionVisible('registerReport');

    $this->actingAs(tenantUser($this->tenant, ['payroll.view', 'compensation.view']));
    expect(collect(PayrollRegisters::uploads(['salary', 'stat_overrides'])->getActions())->filter(fn (Action $a) => $a->isVisible()))->toBeEmpty()
        ->and(PayrollRegisters::actionsMenu()->isVisible())->toBeFalse();
});
