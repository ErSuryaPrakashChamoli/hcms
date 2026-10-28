<?php

use App\Domain\Compliance\Models\EmployeeTaxDeclaration;
use App\Domain\Compliance\Models\TdsEmployeeInvestment;
use App\Domain\Compliance\Services\InvestmentDeclarations;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Services\PayrollCalculator;
use App\Filament\Pages\DeclareInvestments;
use App\Filament\Resources\TdsInvestments\TdsInvestmentResource;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/PayrollTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->company = payrollCompany();
    $this->employee = salariedEmployee(1200000, ['payroll.payslip']);
    $this->me = $this->employee->user;
    $this->declare = fn (array $items, array $extra = [], bool $submit = false, $user = null, $employee = null) => app(InvestmentDeclarations::class)
        ->save($user ?? $this->me, $employee ?? $this->employee, '2026-27', ['regime' => 'old', 'items' => $items, ...$extra], $submit);
});

it('saves one proof row per item and the section totals payroll reads', function () {
    $declaration = ($this->declare)([
        '80C.ppf' => ['amount' => 50000],
        '80C.elss' => ['amount' => 30000],
        '80C.other' => ['amount' => 5000, 'description' => 'Post office time deposit'],
        '80D.parents' => ['amount' => 18000],
        'HRA_RENT.rent' => ['amount' => 240000, 'details' => ['monthly_rent' => 20000, 'months' => 12, 'landlord_name' => 'R. Sharma', 'landlord_pan' => 'ABCDE1234F']],
        '80E.education_loan_interest' => ['amount' => 0], // nothing declared: no row
    ], ['metro' => true, 'previous_employer_income' => 300000, 'previous_employer_tds' => 12000], submit: true);

    expect($declaration->status)->toBe('submitted')
        ->and($declaration->regime)->toBe('old')
        ->and($declaration->declarations)->toEqual(['80C' => 85000, '80D' => 18000, 'HRA_RENT' => 240000, 'METRO' => 1])
        ->and((float) $declaration->previous_employer_income)->toBe(300000.0);

    $rows = TdsEmployeeInvestment::query()->where('employee_id', $this->employee->id)->get()->keyBy('item_key');
    expect($rows->keys()->sort()->values()->all())->toBe(['80C.elss', '80C.other', '80C.ppf', '80D.parents', 'HRA_RENT.rent'])
        ->and($rows['80C.ppf']->description)->toBe('Public Provident Fund (PPF)')
        ->and($rows['80C.other']->description)->toBe('Post office time deposit')
        ->and($rows['80C.ppf']->proof_status)->toBe('pending')
        ->and($rows['80C.ppf']->employee_tax_declaration_id)->toBe($declaration->id)
        ->and($rows['HRA_RENT.rent']->details['landlord_pan'])->toBe('ABCDE1234F')
        // The landlord's details are encrypted at rest.
        ->and(DB::table('tds_employee_investments')->where('item_key', 'HRA_RENT.rent')->value('details'))->not->toContain('ABCDE1234F');

    // The tax computation reads the totals: 80C and 80D count in the old regime.
    $c = app(PayrollCalculator::class)->calculate($this->employee->refresh(), PayrollPeriod::for($this->company, 2026, 9));
    expect($c->inputs['tax']['regime'])->toBe('old')->and($c->inputs['tax']['hra_exempt'])->toBeGreaterThan(0);
});

it('removes unticked items, keeps verified proofs and sends a corrected rejected proof back to pending', function () {
    ($this->declare)(['80C.ppf' => ['amount' => 50000], '80C.elss' => ['amount' => 30000], '80D.self_family' => ['amount' => 12000], '80TTA.savings_interest' => ['amount' => 4000]]);
    $rows = TdsEmployeeInvestment::query()->get()->keyBy('item_key');
    $rows['80C.ppf']->update(['proof_status' => 'verified', 'proof_amount' => 45000]);
    $rows['80D.self_family']->update(['proof_status' => 'rejected', 'proof_amount' => 0, 'notes' => 'Receipt is for a car policy']);
    $rows['80TTA.savings_interest']->update(['proof_status' => 'rejected', 'notes' => 'No statement']);

    // PPF left out (it is verified), ELSS unticked, 80D corrected, 80TTA unchanged.
    $declaration = ($this->declare)(['80D.self_family' => ['amount' => 15000], '80TTA.savings_interest' => ['amount' => 4000], '80C.ppf' => ['amount' => 1]]);
    $rows = TdsEmployeeInvestment::query()->get()->keyBy('item_key');

    expect($rows->has('80C.elss'))->toBeFalse()
        ->and((float) $rows['80C.ppf']->declared_amount)->toBe(50000.0) // a verified item is never changed here
        ->and($rows['80C.ppf']->proof_status)->toBe('verified')
        ->and($rows['80D.self_family']->proof_status)->toBe('pending')
        ->and($rows['80D.self_family']->proof_amount)->toBeNull()
        ->and($rows['80TTA.savings_interest']->proof_status)->toBe('rejected') // unchanged: still rejected
        ->and($declaration->declarations)->toEqual(['80C' => 50000, '80D' => 15000, '80TTA' => 4000]);
});

it('keeps totals it does not own and sets the metro flag only with rent', function () {
    EmployeeTaxDeclaration::query()->create(['employee_id' => $this->employee->id, 'financial_year' => '2026-27', 'regime' => 'old', 'declarations' => ['80CCD2' => 60000, '80C' => 90000, 'METRO' => 1], 'status' => 'draft']);

    $declaration = ($this->declare)(['80C.other' => ['amount' => 90000, 'description' => 'Declared earlier']], ['metro' => true]);

    expect($declaration->declarations)->toEqual(['80CCD2' => 60000, '80C' => 90000]);
});

it('lets employees file only their own declaration and only until payroll verifies it', function () {
    $colleague = salariedEmployee(900000, ['payroll.payslip']);
    expect(fn () => ($this->declare)(['80C.ppf' => ['amount' => 1000]], employee: $colleague))->toThrow(RuntimeException::class);

    $declaration = ($this->declare)(['80C.ppf' => ['amount' => 1000]], submit: true);
    $declaration->update(['status' => 'verified']);
    expect(fn () => ($this->declare)(['80C.ppf' => ['amount' => 2000]]))->toThrow(RuntimeException::class, 'Payroll has verified this declaration');

    // Payroll files for anyone and may reopen a verified one (it goes back to submitted for verification).
    $payroll = tenantUser($this->tenant, ['payroll.manage', 'payroll.view']);
    $this->actingAs($payroll);
    expect(($this->declare)(['80C.ppf' => ['amount' => 2000]], user: $payroll, submit: true)->status)->toBe('submitted')
        ->and(($this->declare)(['80D.parents' => ['amount' => 5000]], user: $payroll, employee: $colleague)->declarations)->toEqual(['80D' => 5000]);
});

it('walks an employee through the guided declaration and submits it', function () {
    $this->actingAs($this->me);

    $this->get(DeclareInvestments::getUrl())->assertOk()
        ->assertSee('Public Provident Fund (PPF)')
        ->assertSee('Children’s tuition fees', false)
        ->assertSee('Health insurance for your parents')
        ->assertSee('Which tax regime do you choose?');

    Livewire::test(DeclareInvestments::class)
        ->assertSet('data.employee_id', $this->employee->id)
        ->assertSet('data.regime', 'new')
        ->set('data.regime', 'old')
        ->set('data.s_80C.chosen', ['ppf', 'tuition_fees'])
        ->set('data.s_80C.amounts.ppf', 60000)
        ->set('data.s_80C.amounts.tuition_fees', 110000)
        ->set('data.rent.pays', true)
        ->set('data.rent.monthly', 15000)
        ->set('data.rent.months', 10)
        ->set('data.rent.landlord_pan', 'abcde1234f')
        ->call('submit') // without the confirmation: refused
        ->assertNotified('Tick the confirmation in the Review step to submit')
        ->set('data.confirm', true)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertNotified('Declaration submitted to payroll');

    $declaration = EmployeeTaxDeclaration::query()->where('employee_id', $this->employee->id)->sole();
    expect($declaration->status)->toBe('submitted')
        ->and($declaration->declarations)->toEqual(['80C' => 170000, 'HRA_RENT' => 150000, 'METRO' => 0])
        ->and(TdsEmployeeInvestment::query()->where('item_key', 'HRA_RENT.rent')->sole()->details)->toMatchArray(['monthly_rent' => 15000, 'months' => 10, 'landlord_pan' => 'ABCDE1234F']);

    // Reopening shows what was declared, ticked per item.
    Livewire::test(DeclareInvestments::class)
        ->assertSet('data.regime', 'old')
        ->assertSet('data.s_80C.chosen', ['ppf', 'tuition_fees'])
        ->assertSet('data.s_80C.amounts.tuition_fees', 110000.0)
        ->assertSet('data.rent.pays', true)
        ->assertSet('data.rent.months', 10);
});

it('asks for the amount of every ticked item', function () {
    $this->actingAs($this->me);

    Livewire::test(DeclareInvestments::class)
        ->set('data.regime', 'old')
        ->set('data.s_80D.chosen', ['parents'])
        ->set('data.confirm', true)
        ->call('submit')
        ->assertHasErrors(['data.s_80D.amounts.parents' => 'required']);

    expect(EmployeeTaxDeclaration::query()->count())->toBe(0);
});

it('opens a legacy lump sum in the section catch-all and keeps a verified declaration read-only', function () {
    EmployeeTaxDeclaration::query()->create(['employee_id' => $this->employee->id, 'financial_year' => '2026-27', 'regime' => 'old', 'declarations' => ['80C' => 90000, '80D' => 20000], 'status' => 'verified']);
    $this->actingAs($this->me);

    Livewire::test(DeclareInvestments::class)
        ->assertSet('data.s_80C.chosen', ['other'])
        ->assertSet('data.s_80C.amounts.other', 90000.0)
        ->assertSet('data.s_80C.other_description', 'Declared earlier')
        ->assertSet('data.s_80D.chosen', ['self_family'])
        ->assertSee('Payroll has verified this declaration')
        ->call('saveDraft')
        ->assertNotified('Payroll has verified this declaration. Ask payroll to make any change.');
});

it('is open to employees with their own record and to payroll, and linked from the proofs screen', function () {
    $this->actingAs(tenantUser($this->tenant, ['payroll.payslip'])); // no employee record
    $this->get(DeclareInvestments::getUrl())->assertForbidden();

    $payroll = tenantUser($this->tenant, ['payroll.manage', 'payroll.view', 'compliance.returns.view', 'compliance.tds.manage']);
    $this->actingAs($payroll);
    $this->get(DeclareInvestments::getUrl(['employee' => $this->employee->id, 'year' => '2026-27']))->assertOk()->assertSee($this->employee->employee_code);
    $this->get(TdsInvestmentResource::getUrl('index'))->assertOk()->assertSee('Declare investments');
});

it('shows the limit payroll applies and what is over it', function () {
    expect(app(InvestmentDeclarations::class)->counting('2026-27', 'old'))
        ->toMatchArray(['80C' => ['allowed' => true, 'limit' => 150000.0], '80E' => ['allowed' => true, 'limit' => null], 'HRA_RENT' => ['allowed' => true, 'limit' => null]])
        ->and(app(InvestmentDeclarations::class)->counting('2026-27', 'new')['80C']['allowed'])->toBeFalse();
});
