<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Services\ComplianceRules;
use App\Domain\Configuration\Services\SetupCatalogue;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Experience\Services\ProfileSectionVisibility;
use App\Domain\Payroll\Contracts\PaidPayReader;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Payroll\Services\MyPay;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Auth\ConfirmPayPassword;
use App\Filament\Pages\MyCompensation;
use App\Filament\Pages\MyDay;
use App\Filament\Pages\MyPayslips;
use App\Filament\Pages\MySalaryYtd;
use App\Filament\Resources\Payslips\PayslipResource;
use App\Filament\Support\PayPasswordGate;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/*
| HROne parity MS-9 (matrix 22 §22.5, §22.6, §22.7.1 #3): My payslips by financial year with salary and tax cards (the tax
| slip from the payslip's tax snapshot, audited), My compensation tabs with the statutory block, pay mode and the Code on
| Wages verdict (verified rules only), and the password asked again before the pay pages when the organisation asks for
| it (owner decision PRV-4 / D-MS10; default off = today's behaviour). Own data only, finalized payroll only.
*/

beforeEach(function () {
    $this->travelTo('2026-08-27 10:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->approver = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    Filament::getPanel('admin')->boot();
    $this->company = payrollCompany();
    $this->employee = salariedEmployee(600000, config('peopleos.roles.employee.permissions'), from: '2025-04-01');
    $this->colleague = salariedEmployee(900000, config('peopleos.roles.employee.permissions'), from: '2025-04-01');
    foreach ([$this->employee, $this->colleague] as $i => $e) {
        EmployeeStatutoryDetail::query()->updateOrCreate(['employee_id' => $e->id], ['pan' => 'ABCDE123'.$i.'F', 'uan' => '10020030040'.$i, 'pf_applicable' => true, 'esic_applicable' => false, 'pt_applicable' => true]);
        EmployeeBankAccount::create(['employee_id' => $e->id, 'account_holder_name' => 'X', 'bank_name' => 'HDFC', 'account_number' => '98765432'.$i, 'ifsc' => 'HDFC0000001', 'is_primary' => true]);
    }
    $runs = app(PayrollRuns::class);
    foreach ([8, 9] as $month) {
        $this->travelTo(sprintf('2026-%02d-27 10:00:00', $month));
        $run = $runs->calculate($runs->open($this->company, 2026, $month, $this->hr), $this->hr);
        $runs->finalize($runs->approve($runs->validate($run), $this->approver), $this->approver);
    }
    // October is calculated but not finalized: it never shows.
    $this->travelTo('2026-10-27 10:00:00');
    $runs->calculate($runs->open($this->company, 2026, 10, $this->hr), $this->hr);
});

function ms9Set(string $key, mixed $value): void
{
    app(SettingsRepository::class)->set($key, $value, 'test');
}

function ms9Rule(string $code, ?string $state, array $parameters): ComplianceRule
{
    $version = (int) ComplianceRule::query()->where('code', $code)->when($state === null, fn ($q) => $q->whereNull('state'), fn ($q) => $q->where('state', $state))->max('version') + 1;
    $rule = ComplianceRule::query()->create(['jurisdiction' => 'IN', 'code' => $code, 'state' => $state, 'name' => $code.' test rule', 'version' => $version, 'effective_from' => '2025-01-01', 'parameters' => $parameters, 'status' => ComplianceRule::ACTIVE]);
    DB::table('compliance_rules')->where('id', $rule->id)->update(['verification_status' => ComplianceRule::VERIFIED]);
    app(ComplianceRules::class)->forget();

    return $rule->refresh();
}

/** @return list<string> the pay months of the cards on My payslips */
function ms9Cards($page): array
{
    return $page->instance()->payslips()->map(fn (Payslip $p) => $p->get('period.label'))->all();
}

// ---- defaults ----------------------------------------------------------------------------------------------------------

it('keeps today\'s behaviour by default: no password asked, My compensation without tabs or new blocks', function () {
    expect(config('peopleos.settings')['compensation.self_service.confirm_password'])->toBeFalse()
        ->and(config('peopleos.settings')['compensation.self_service.show_statutory'])->toBeFalse()
        ->and(config('peopleos.settings')['compensation.self_service.show_pay_mode'])->toBeFalse();
    $settings = collect(app(SetupCatalogue::class)->settingsFor('payroll'))->pluck('key')->all();
    expect($settings)->toContain('compensation.self_service.confirm_password', 'compensation.self_service.show_statutory', 'compensation.self_service.show_pay_mode', 'payroll.payslip.password_help');

    $this->actingAs($this->employee->user);
    foreach ([MyCompensation::getUrl(), MyPayslips::getUrl(), MySalaryYtd::getUrl(), PayslipResource::getUrl('index')] as $url) {
        $this->get($url)->assertOk();
    }
    Livewire::test(MyCompensation::class)->assertOk()->assertSee('In force today')->assertDontSee('Salary insights')->assertDontSee('Statutory details')->assertDontSee('Code on Wages');
    expect(PayPasswordGate::required())->toBeFalse();
});

// ---- the password asked again ----------------------------------------------------------------------------------------

it('asks for the password again before the pay pages when the organisation asks for it, for 15 minutes', function () {
    ms9Set('compensation.self_service.confirm_password', true);
    $this->actingAs($this->employee->user);
    $own = Payslip::query()->where('employee_id', $this->employee->id)->firstOrFail();

    foreach ([MyCompensation::getUrl(), MyPayslips::getUrl(), MySalaryYtd::getUrl(), PayslipResource::getUrl('index'), PayslipResource::getUrl('view', ['record' => $own])] as $url) {
        $this->get($url)->assertRedirect(PayPasswordGate::url());
    }
    // Pages without pay are not asked; My day shows no pay figure until the password is given.
    $this->get(MyDay::getUrl())->assertOk()->assertDontSee('Net '.number_format((float) $own->get('totals.net'), 2));

    // Payroll staff reading payslips are not asked; their own pay pages are.
    $this->actingAs($this->hr);
    $this->get(PayslipResource::getUrl('index'))->assertOk();

    // A confirmation within the timeout opens the pages; after it they ask again.
    $this->actingAs($this->employee->user);
    $this->withSession(['auth.password_confirmed_at' => now()->unix()]);
    $this->get(MyCompensation::getUrl())->assertOk();
    $this->get(PayslipResource::getUrl('view', ['record' => $own]))->assertOk();
    $this->travel(16)->minutes();
    $this->get(MyPayslips::getUrl())->assertRedirect(PayPasswordGate::url());
});

it('confirms the login password on the confirmation page, rate limited, and returns to the page asked for', function () {
    ms9Set('compensation.self_service.confirm_password', true);
    $this->actingAs($this->employee->user);
    session()->put('url.intended', MySalaryYtd::getUrl());

    Livewire::test(ConfirmPayPassword::class)->assertOk()->assertSee('Enter your password')
        ->set('data.password', 'wrong-password')->call('confirm')->assertHasErrors(['data.password']);
    expect(session()->has('auth.password_confirmed_at'))->toBeFalse();

    Livewire::test(ConfirmPayPassword::class)->set('data.password', 'password')->call('confirm')->assertRedirect(MySalaryYtd::getUrl());
    expect(session('auth.password_confirmed_at'))->toBeGreaterThan(0)
        ->and(PayPasswordGate::required())->toBeFalse();

    // With nothing to confirm (setting off) the page just returns.
    ms9Set('compensation.self_service.confirm_password', false);
    Livewire::test(ConfirmPayPassword::class)->assertRedirect();
});

it('stops a pay page\'s later requests once the confirmation has run out, without rendering', function () {
    $this->actingAs($this->employee->user);
    $page = Livewire::test(MyPayslips::class)->assertOk();
    ms9Set('compensation.self_service.confirm_password', true);
    $page->set('year', '2025-26')->assertRedirect(PayPasswordGate::url());
    expect(session('url.intended'))->toBe(MyPayslips::getUrl());
});

// ---- My payslips -----------------------------------------------------------------------------------------------------

it('lists the own payslips of a financial year with salary and tax cards, finalized payroll only', function () {
    $this->actingAs($this->employee->user);
    $page = Livewire::test(MyPayslips::class)->assertOk()->assertSet('year', '2026-27')
        ->assertSee('Aug, 2026')->assertSee('Sep, 2026')->assertDontSee('Oct, 2026');
    expect(ms9Cards($page))->toBe(['Aug 2026', 'Sep 2026'])
        ->and($page->instance()->years())->toContain('2026-27');

    $page->set('search', 'sept');
    expect(ms9Cards($page))->toBe(['Sep 2026']);
    $page->set('search', '');

    // Another year, an unknown year.
    $page->set('year', '2025-26');
    expect($page->get('year'))->toBe('2026-27');

    // The tax slip: read from the snapshot, audited, own payslips only.
    $own = Payslip::query()->where('employee_id', $this->employee->id)->orderBy('id')->firstOrFail();
    expect($own->get('tax'))->not->toBeNull();
    $page->mountAction('taxSlip', ['payslip' => $own->id])->assertMountedActionModalSee(['Taxable income', 'Tax regime', 'Deducted this month']);
    expect(AuditEvent::query()->where('action', 'PAYSLIP_ACCESSED')->where('entity_id', (string) $own->id)->get()->contains(fn ($e) => ($e->metadata['part'] ?? null) === 'tax_slip'))->toBeTrue();

    $theirs = Payslip::query()->where('employee_id', $this->colleague->id)->firstOrFail();
    $before = AuditEvent::query()->where('entity_id', (string) $theirs->id)->where('action', 'PAYSLIP_ACCESSED')->count();
    Livewire::test(MyPayslips::class)->mountAction('taxSlip', ['payslip' => $theirs->id])->assertMountedActionModalSee('No tax was worked out on this payslip.');
    expect(AuditEvent::query()->where('entity_id', (string) $theirs->id)->where('action', 'PAYSLIP_ACCESSED')->count())->toBe($before);
});

it('shows the organisation\'s payslip help text and a readable tax slip', function () {
    ms9Set('payroll.payslip.password_help', 'Payslip files open with the last four characters of your PAN and your month and year of joining.');
    $this->actingAs($this->employee->user);
    Livewire::test(MyPayslips::class)->assertSee('Payslip files open with the last four characters');

    $slip = MyPay::taxSlip(Payslip::query()->where('employee_id', $this->employee->id)->firstOrFail());
    $labels = collect($slip)->flatMap(fn ($part) => collect($part['rows'])->pluck('label'))->all();
    expect(collect($slip)->pluck('heading')->all())->toContain('Tax year', 'Income', 'Exemptions and deductions', 'Tax', 'Tax deducted')
        ->and($labels)->toContain('Financial year', 'Projected gross salary for the year', 'Standard deduction', 'Taxable income', 'Tax for the year', 'Deducted this month');

    // A payslip without a tax snapshot has no tax slip.
    $payslip = new Payslip(['snapshot' => ['tax' => null]]);
    expect(MyPay::taxSlip($payslip))->toBeNull();
});

// ---- My compensation ---------------------------------------------------------------------------------------------------

it('shows the statutory block and the pay mode only when the organisation allows, following the Bank profile section', function () {
    ms9Set('compensation.self_service.show_statutory', true);
    ms9Set('compensation.self_service.show_pay_mode', true);
    $this->actingAs($this->employee->user);
    $page = Livewire::test(MyCompensation::class)->assertOk()->assertSee('Statutory details')->assertSee('Provident fund')->assertSee('Professional tax')
        ->assertSee('Bank transfer')->assertSee('Sep 2026')->assertDontSee('100200300400');
    $statutory = $page->instance()->getStatutory();
    $pf = collect($statutory['rows'])->firstWhere('scheme', 'Provident fund');
    expect($pf['applies'])->toBe('Yes')
        ->and($pf['latest'])->toBeGreaterThan(0.0)
        ->and(collect($statutory['rows'])->firstWhere('scheme', 'ESI')['applies'])->toBe('No')
        ->and(collect($statutory['rows'])->firstWhere('scheme', 'Professional tax')['detail'])->toBe('State KA');

    // The Bank profile section hidden from employees hides the pay mode here too.
    ms9Set('profile.section_visibility', ['bank' => ['employee' => false, 'manager' => true, 'others' => true]] + config('peopleos.settings')['profile.section_visibility']);
    app()->forgetInstance(ProfileSectionVisibility::class);
    expect(Livewire::test(MyCompensation::class)->instance()->getPayMode())->toBeNull();
});

it('gives the Code on Wages verdict only from verified rules, on the insights tab', function () {
    ms9Set('compensation.self_service.show_wage_split', true);
    $this->actingAs($this->employee->user);
    $page = Livewire::test(MyCompensation::class)->assertSee('Salary structure')->assertSee('Salary insights');
    $page->call('setTab', 'insights')->assertSee('Code on Wages')->assertSee('No verified rule')->assertDontSee('In force today');
    $verdict = $page->instance()->getWageCode();
    expect($verdict['share']['state'])->toBe('no_rule')->and($verdict['minimum']['state'])->toBe('no_rule')
        ->and($verdict['share']['threshold'])->toBeNull();

    // A verified wage rule: the share against the rule's own figure.
    $latest = app(PaidPayReader::class)->latestPaid($this->employee);
    ms9Rule('WAGES', null, ['min_wage_share' => 50]);
    $verdict = Livewire::test(MyCompensation::class)->instance()->getWageCode();
    expect($verdict['share']['state'])->toBe($latest['wage_share'] >= 50 ? 'ok' : 'attention')->and($verdict['share']['threshold'])->toBe(50.0);
    ms9Rule('WAGES', null, ['min_wage_share' => 99]);
    expect(Livewire::test(MyCompensation::class)->instance()->getWageCode()['share']['state'])->toBe('attention');

    // Minimum wage: only with a verified rule for the state the pay was calculated for; another state's rule is not used.
    expect($latest['state'])->not->toBeNull();
    ms9Rule('MIN_WAGE', 'ZZ', ['monthly_minimum' => 1]);
    expect(Livewire::test(MyCompensation::class)->instance()->getWageCode()['minimum']['state'])->toBe('no_rule');
    ms9Rule('MIN_WAGE', $latest['state'], ['monthly_minimum' => $latest['wages_full_month'] + 1000]);
    $verdict = Livewire::test(MyCompensation::class)->instance()->getWageCode();
    expect($verdict['minimum']['state'])->toBe('attention')->and($verdict['minimum']['text'])->toContain('less than the minimum wage');

    // The switch off: no insights tab, no verdict.
    ms9Set('compensation.self_service.show_wage_split', false);
    Livewire::test(MyCompensation::class)->assertDontSee('Salary insights');
    expect(Livewire::test(MyCompensation::class)->instance()->getWageCode())->toBeNull();
});

// ---- own data only -----------------------------------------------------------------------------------------------------

it('answers only for the viewer\'s own record, and nothing in another tenant', function () {
    $pay = app(MyPay::class);
    $me = $this->employee->user;
    expect($pay->payslips($me, $this->colleague, '2026-27'))->toBeEmpty()
        ->and($pay->statutory($me, $this->colleague)['rows'])->toBe([])
        ->and($pay->wageCode($me, $this->colleague))->toBeNull()
        ->and($pay->payslip($me, $this->colleague, (int) Payslip::query()->where('employee_id', $this->colleague->id)->value('id')))->toBeNull()
        ->and($pay->payslips($me, $this->employee, '2026-27'))->toHaveCount(2);

    // An HR user opening My payslips sees their own (none), never other people's.
    $hrEmployee = salariedEmployee(300000, ['*'], from: '2026-11-01');
    $this->actingAs($hrEmployee->user);
    expect(Livewire::test(MyPayslips::class)->instance()->payslips())->toBeEmpty();

    $other = provisionTenant('Globex');
    actAsTenant($other);
    expect($pay->payslips($me, $this->employee, '2026-27'))->toBeEmpty();
});
