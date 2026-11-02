<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Claims\Models\ReimbursementClaim;
use App\Domain\Claims\Services\ReimbursementClaims;
use App\Domain\Compliance\Models\EmployeeTaxDeclaration;
use App\Domain\Compliance\Models\TdsEmployeeInvestment;
use App\Domain\Compliance\Services\InvestmentDeclarations;
use App\Domain\Compliance\Services\InvestmentProofs;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Services\RequestRegistry;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserAccessScope;
use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\DeclareInvestments;
use App\Filament\Pages\NewRequest;
use App\Filament\Pages\PayrollDesk;
use App\Filament\Resources\ExitCases\ExitCaseResource;
use App\Filament\Resources\ExitCases\Pages\ListExitCases;
use App\Filament\Resources\ExpenseAdvances\ExpenseAdvanceResource;
use App\Filament\Resources\ExpenseAdvances\Pages\ListExpenseAdvances;
use App\Filament\Resources\MyInvestmentProofs\Pages\ListMyInvestmentProofs;
use App\Filament\Resources\ReimbursementClaims\Pages\ListReimbursementClaims;
use App\Filament\Resources\ReimbursementClaims\ReimbursementClaimResource;
use App\Filament\Resources\TaxDeclarations\Pages\ManageTaxDeclarations;
use App\Filament\Resources\TaxDeclarations\TaxDeclarationResource;
use App\Filament\Support\OnBehalfPicker;
use App\Filament\Support\PayrollRegisters;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/*
| HROne parity AC-2 (HR Desk "Request on behalf"; matrix 25 §25.3.1 #3, §25.3.2 #3, §25.3.3 #3; matrix 20 §20.1.0 #15,
| R.2.7 #1): HR claims a reimbursement, declares investments and submits a proof for an employee in its access scope —
| never for itself, never outside the scope, never without payroll.manage — through the employee's own services and rules
| (balance, bills, windows as the codebase already lets payroll, the same approvals), recorded as filed on the employee's
| behalf; the employee's own forms are unchanged; the request types name their on-behalf forms (claims, declarations,
| proofs, expense advances, resignations), which open directly by URL (?action=).
*/

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    Filament::getPanel('admin')->boot();
    $this->company = payrollCompany();
    $this->south = Company::factory()->create(['name' => 'South']);
    // The employee, a colleague in the same company and someone in another company.
    [$this->user, $this->employee] = ac2Person($this->company, ['payroll.request', 'payroll.payslip']);
    [, $this->colleague] = ac2Person($this->company, ['payroll.request', 'payroll.payslip']);
    [, $this->southEmployee] = ac2Person($this->south, ['payroll.request', 'payroll.payslip']);
    // HR: payroll for the first company only, with an employee record of its own.
    [$this->hr, $this->hrEmployee] = ac2Person($this->company, ['payroll.manage', 'payroll.view', 'payroll.payslip', 'payroll.request']);
    UserAccessScope::query()->create(['user_id' => $this->hr->id, 'dimension' => 'company', 'scope_id' => $this->company->id]);
    $this->checker = tenantUser($this->tenant, ['payroll.manage', 'payroll.view', 'compliance.tds.manage']);
    $this->fuel = SalaryComponent::create(['name' => 'Fuel reimbursement', 'code' => 'FUEL', 'type' => 'reimbursement', 'classification' => 'reimbursement', 'calculation_method' => 'fixed',
        'taxable' => false, 'include_in_ctc' => true, 'include_in_gross' => false, 'is_recurring' => true, 'is_proratable' => false, 'pay_on_claim' => true, 'sort_order' => 50, 'status' => 'active']);
    $this->actingAs($this->checker);
    foreach ([$this->employee, $this->colleague, $this->southEmployee, $this->hrEmployee] as $employee) {
        app(ReimbursementClaims::class)->opening($employee, $this->fuel, 2026, 3000, 'Opening balance');
    }
    $this->actingAs($this->hr);
});

/** @return array{0: User, 1: Employee} a login with an active employee record in the company */
function ac2Person(Company $company, array $permissions): array
{
    $user = tenantUser(test()->tenant, $permissions);
    $employee = Employee::factory()->create(['user_id' => $user->id, 'joining_date' => '2025-01-01']);
    EmployeePosition::query()->create(['employee_id' => $employee->id, 'company_id' => $company->id, 'effective_from' => '2025-01-01']);

    return [$user, $employee];
}

function ac2Setting(string $key, mixed $value): void
{
    app(SettingsRepository::class)->set($key, $value, 'test');
}

function ac2Bill(string $name = 'bill.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%test\n");
}

/** The SUBMITTED audit event of a record. */
function ac2Submitted(object $record): ?AuditEvent
{
    return AuditEvent::query()->where('entity_type', $record::class)->where('entity_id', $record->getKey())->where('action', 'SUBMITTED')->latest('id')->first();
}

// ---- Reimbursement claim for an employee -----------------------------------------------------------------------------

it('lets HR claim a reimbursement for an employee in scope under the employee\'s rules; it waits for approval and is recorded as filed on their behalf', function () {
    expect(OnBehalfPicker::candidates('payroll')->pluck('employees.id')->all())->toContain($this->employee->id, $this->colleague->id)
        ->not->toContain($this->hrEmployee->id)->not->toContain($this->southEmployee->id);

    // The employee's balance and bill rules apply: more than the balance, and no bills attached, are refused.
    $claim = fn (array $data) => Livewire::test(ListReimbursementClaims::class)->assertActionVisible('claimForEmployee')
        ->callAction('claimForEmployee', $data + ['employee_id' => $this->employee->id, 'salary_component_id' => $this->fuel->id, 'claim_date' => '2026-09-20', 'description' => 'Fuel for client visits']);
    $claim(['amount' => 4000, 'attachments' => [ac2Bill()]])->assertNotified('Not sent');
    $claim(['amount' => 1500])->assertNotified('Not sent');
    expect(ReimbursementClaim::query()->count())->toBe(0);

    $claim(['amount' => 1500, 'attachments' => [ac2Bill()]])->assertHasNoActionErrors();
    $filed = ReimbursementClaim::query()->sole();
    $submitted = ac2Submitted($filed);
    expect($filed->employee_id)->toBe($this->employee->id)->and($filed->requested_by)->toBe($this->hr->id)->and($filed->status)->toBe('pending')
        ->and($filed->attachments)->toHaveCount(1)
        ->and($submitted->metadata)->toEqual(['component' => 'FUEL', 'on_behalf' => true, 'employee_id' => $this->employee->id])->and($submitted->actor_id)->toBe($this->hr->id)
        // Not approved here: it waits in the Approval Center for the payroll approvers, as the employee's own claim would.
        ->and(collect(app(ApprovalCenter::class)->pending($this->checker))->firstWhere('type', 'reimbursement_claim')?->requestedBy)->toBe($this->hr->name)
        ->and(app(ReimbursementClaims::class)->balance($this->employee, $this->fuel, 2026))->toBe(1500.0);

    // Never one's own (that is "Claim a reimbursement"), never outside the scope — on the screen and in the service.
    $claim(['employee_id' => $this->hrEmployee->id, 'amount' => 100, 'attachments' => [ac2Bill()]])->assertNotified('Not sent');
    $claim(['employee_id' => $this->southEmployee->id, 'amount' => 100, 'attachments' => [ac2Bill()]])->assertHasActionErrors(['employee_id']);
    expect(ReimbursementClaim::query()->whereIn('employee_id', [$this->hrEmployee->id, $this->southEmployee->id])->exists())->toBeFalse()
        ->and(fn () => OnBehalfPicker::resolve($this->hrEmployee->id, 'payroll.manage', 'payroll', 'Claim a reimbursement'))->toThrow(RuntimeException::class, 'This is your own record: use "Claim a reimbursement".')
        ->and(fn () => OnBehalfPicker::resolve($this->southEmployee->id, 'payroll.manage', 'payroll', 'Claim a reimbursement'))->toThrow(RuntimeException::class, 'Choose an employee in your access scope.')
        ->and(fn () => app(ReimbursementClaims::class)->claim($this->southEmployee, $this->fuel, 100, '2026-09-20', 'South', [], ['claims/x.pdf'], $this->hr))
        ->toThrow(RuntimeException::class, 'You cannot claim a reimbursement for this employee.');

    // Without payroll.manage: not offered, and the service refuses a claim for a colleague.
    $this->actingAs($this->user);
    Livewire::test(ListReimbursementClaims::class)->assertActionHidden('claimForEmployee');
    expect(fn () => OnBehalfPicker::resolve($this->colleague->id, 'payroll.manage', 'payroll', 'Claim a reimbursement'))->toThrow(RuntimeException::class, 'You cannot file this for other employees.')
        ->and(fn () => app(ReimbursementClaims::class)->claim($this->colleague, $this->fuel, 100, '2026-09-20', 'For a colleague', [], ['claims/x.pdf'], $this->user))
        ->toThrow(RuntimeException::class, 'You cannot claim a reimbursement for this employee.');
});

it('keeps the employee\'s own claim as before: their own form, their own request, no on-behalf mark', function () {
    $this->actingAs($this->user);
    Livewire::test(ListReimbursementClaims::class)->assertActionVisible('claim')
        ->callAction('claim', ['salary_component_id' => $this->fuel->id, 'claim_date' => '2026-09-20', 'amount' => 800, 'description' => 'Fuel', 'attachments' => [ac2Bill()]])
        ->assertHasNoActionErrors()->assertNotified('Claim sent for approval');
    $own = ReimbursementClaim::query()->sole();
    expect($own->requested_by)->toBe($this->user->id)->and($own->status)->toBe('pending')
        ->and(ac2Submitted($own)->metadata)->toEqual(['component' => 'FUEL']);

    // HR's own claim also stays its own (the "Claim a reimbursement" path), unmarked.
    $this->actingAs($this->hr);
    Livewire::test(ListReimbursementClaims::class)
        ->callAction('claim', ['salary_component_id' => $this->fuel->id, 'claim_date' => '2026-09-20', 'amount' => 300, 'description' => 'Mine', 'attachments' => [ac2Bill()]])->assertHasNoActionErrors();
    expect(ac2Submitted(ReimbursementClaim::query()->where('employee_id', $this->hrEmployee->id)->sole())->metadata)->toEqual(['component' => 'FUEL']);
});

// ---- Investment declaration for an employee (proposed) ---------------------------------------------------------------

it('lets HR open the declaration wizard on an employee in scope and submit it for them, outside the window as payroll already may; never for itself', function () {
    // The window is closed: the employee cannot change their declaration, payroll still may (the existing override).
    ac2Setting('payroll.tax.declaration_to', '2026-09-01');
    expect(app(InvestmentDeclarations::class)->canEdit($this->user, $this->employee, null))->toBeFalse();

    Livewire::test(DeclareInvestments::class)->assertActionVisible('declareForEmployee')
        ->callAction('declareForEmployee', ['employee_id' => $this->employee->id, 'financial_year' => '2026-27'])
        ->assertSet('data.employee_id', $this->employee->id)->assertSet('data.financial_year', '2026-27')
        ->set('data.regime', 'old')
        ->set('data.s_80C.chosen', ['ppf'])
        ->set('data.s_80C.amounts.ppf', 50000)
        ->set('data.confirm', true)
        ->call('submit')
        ->assertNotified('Declaration submitted to payroll');

    $declaration = EmployeeTaxDeclaration::query()->where('employee_id', $this->employee->id)->sole();
    $submitted = ac2Submitted($declaration);
    expect($declaration->status)->toBe('submitted')->and($declaration->declarations)->toEqual(['80C' => 50000])
        ->and($submitted->metadata)->toEqual(['on_behalf' => true, 'employee_id' => $this->employee->id, 'financial_year' => '2026-27'])->and($submitted->actor_id)->toBe($this->hr->id)
        ->and(AuditEvent::query()->where('entity_type', $declaration::class)->where('entity_id', $declaration->id)->where('reason', 'Investment declaration submitted to payroll (filed on the employee\'s behalf)')->exists())->toBeTrue()
        ->and(TdsEmployeeInvestment::query()->where('employee_id', $this->employee->id)->sole()->item_key)->toBe('80C.ppf');

    // Never one's own (the wizard itself is one's own declaration), never outside the scope.
    Livewire::test(DeclareInvestments::class)->callAction('declareForEmployee', ['employee_id' => $this->hrEmployee->id, 'financial_year' => '2026-27'])->assertNotified('Not opened');
    Livewire::test(DeclareInvestments::class)->callAction('declareForEmployee', ['employee_id' => $this->southEmployee->id, 'financial_year' => '2026-27'])
        ->assertHasActionErrors(['employee_id'])->assertSet('data.employee_id', $this->hrEmployee->id);
    expect(EmployeeTaxDeclaration::query()->whereIn('employee_id', [$this->hrEmployee->id, $this->southEmployee->id])->exists())->toBeFalse();

    // Opened directly by its URL.
    $this->get(DeclareInvestments::getUrl(['action' => 'declareForEmployee']))->assertOk()->assertSee('declareForEmployee', false);
});

it('keeps the employee\'s own declaration as before and does not offer them the on-behalf form', function () {
    $this->actingAs($this->user);
    Livewire::test(DeclareInvestments::class)->assertActionHidden('declareForEmployee')
        ->assertSet('data.employee_id', $this->employee->id)
        ->set('data.regime', 'old')->set('data.s_80C.chosen', ['ppf'])->set('data.s_80C.amounts.ppf', 20000)->set('data.confirm', true)
        ->call('submit')->assertNotified('Declaration submitted to payroll');
    $declaration = EmployeeTaxDeclaration::query()->where('employee_id', $this->employee->id)->sole();
    expect(ac2Submitted($declaration))->toBeNull()
        ->and(AuditEvent::query()->where('entity_type', $declaration::class)->where('entity_id', $declaration->id)->where('reason', 'Investment declaration submitted to payroll')->exists())->toBeTrue();
    expect(fn () => OnBehalfPicker::resolve($this->colleague->id, 'payroll.manage', 'payroll', 'Declare investments'))->toThrow(RuntimeException::class, 'You cannot file this for other employees.');
});

// ---- Investment proof for an employee (confirmed) --------------------------------------------------------------------

it('lets HR submit the proof of an item an employee in scope declared, outside the proof window as payroll already may; it waits for verification', function () {
    $declare = fn (Employee $employee, User $as) => app(InvestmentDeclarations::class)->save($as, $employee, '2026-27', ['regime' => 'old', 'items' => ['80C.ppf' => ['amount' => 50000]]], true);
    $declare($this->employee, $this->user);
    $declare($this->hrEmployee, $this->hr);
    $this->actingAs($this->checker);
    $declare($this->southEmployee, $this->checker);
    $this->actingAs($this->hr);
    $item = TdsEmployeeInvestment::query()->where('employee_id', $this->employee->id)->sole();
    ac2Setting('payroll.tax.proof_from', '2026-10-01'); // closed for the employee today

    $proof = fn (array $data) => Livewire::test(ManageTaxDeclarations::class)->assertActionVisible('submitProofForEmployee')
        ->callAction('submitProofForEmployee', $data + ['files' => [ac2Bill('ppf.pdf')], 'amount' => 45000]);
    $proof(['employee_id' => $this->employee->id, 'item_id' => $item->id])->assertHasNoActionErrors()->assertNotified('Proof sent to payroll for '.$this->employee->display_name);

    $item->refresh();
    $submitted = ac2Submitted($item);
    expect($item->proof_submitted_at)->not->toBeNull()->and((float) $item->proof_claimed_amount)->toBe(45000.0)->and($item->proof_status)->toBe('pending')
        ->and($item->proof_files)->toHaveCount(1)
        ->and($submitted->metadata)->toEqual(['on_behalf' => true, 'employee_id' => $this->employee->id, 'financial_year' => '2026-27'])->and($submitted->actor_id)->toBe($this->hr->id);
    // Payroll verifies it as for the employee's own proof.
    app(InvestmentProofs::class)->review($item, 'verified', 45000, null, $this->checker);
    expect($item->refresh()->proof_status)->toBe('verified');

    // Never one's own, never outside the scope, never another employee's item.
    $own = TdsEmployeeInvestment::query()->where('employee_id', $this->hrEmployee->id)->sole();
    $south = TdsEmployeeInvestment::withoutGlobalScopes()->where('employee_id', $this->southEmployee->id)->sole();
    $proof(['employee_id' => $this->hrEmployee->id, 'item_id' => $own->id])->assertNotified('Not sent');
    $proof(['employee_id' => $this->southEmployee->id, 'item_id' => $south->id])->assertHasActionErrors(['employee_id']);
    $proof(['employee_id' => $this->colleague->id, 'item_id' => $own->id])->assertHasActionErrors(['item_id']);
    expect($own->refresh()->proof_submitted_at)->toBeNull()
        ->and(fn () => app(InvestmentProofs::class)->submit($south, ['investment-proofs/x.pdf'], 100, $this->hr))->toThrow(RuntimeException::class, 'You cannot submit proofs for this employee.');

    // An employee is not offered the on-behalf form; their own proof stays theirs, unmarked.
    $this->actingAs($this->user);
    Livewire::test(ManageTaxDeclarations::class)->assertActionHidden('submitProofForEmployee');
    $this->travelTo('2026-10-02 10:00:00');
    $mine = TdsEmployeeInvestment::query()->create(['employee_id' => $this->employee->id, 'financial_year' => '2026-27', 'section' => '80D', 'item_key' => '80D.self_family', 'description' => 'Health insurance', 'declared_amount' => 12000, 'proof_status' => 'pending']);
    app(InvestmentProofs::class)->submit($mine, ['investment-proofs/mine.pdf'], 12000, $this->user);
    expect(ac2Submitted($mine))->toBeNull()
        ->and(AuditEvent::query()->where('entity_type', $mine::class)->where('entity_id', $mine->id)->where('reason', 'Investment proof submitted')->exists())->toBeTrue();
    Livewire::test(ListMyInvestmentProofs::class)->assertOk();
});

it('keeps maker and checker apart: whoever filed a claim or a proof for an employee does not also decide it', function () {
    // A claim filed by HR for the employee waits for someone else.
    Livewire::test(ListReimbursementClaims::class)->callAction('claimForEmployee', ['employee_id' => $this->employee->id, 'salary_component_id' => $this->fuel->id,
        'claim_date' => '2026-09-20', 'description' => 'Fuel', 'amount' => 500, 'attachments' => [ac2Bill()]])->assertHasNoActionErrors();
    $filed = ReimbursementClaim::query()->sole();
    expect(fn () => app(ReimbursementClaims::class)->approve($filed, null, null, $this->hr))
        ->toThrow(RuntimeException::class, 'You filed this claim for the employee, so someone else decides it.')
        ->and(fn () => app(ReimbursementClaims::class)->reject($filed, 'No', $this->hr))
        ->toThrow(RuntimeException::class, 'You filed this claim for the employee, so someone else decides it.');
    app(ReimbursementClaims::class)->approve($filed, null, null, $this->checker);
    expect($filed->refresh()->status)->toBe('approved');

    // A proof submitted by HR for the employee is verified by someone else.
    app(InvestmentDeclarations::class)->save($this->user, $this->employee, '2026-27', ['regime' => 'old', 'items' => ['80C.ppf' => ['amount' => 50000]]], true);
    $item = TdsEmployeeInvestment::query()->where('employee_id', $this->employee->id)->sole();
    // A payroll person who may both submit and verify proofs submits one for the employee, so someone else verifies it.
    [$filer] = ac2Person($this->company, ['payroll.manage', 'payroll.view', 'compliance.tds.manage']);
    UserAccessScope::query()->create(['user_id' => $filer->id, 'dimension' => 'company', 'scope_id' => $this->company->id]);
    $this->actingAs($filer);
    app(InvestmentProofs::class)->submit($item, ['investment-proofs/ppf.pdf'], 45000, $filer);
    expect(fn () => app(InvestmentProofs::class)->review($item->refresh(), 'verified', 45000, null, $filer))
        ->toThrow(RuntimeException::class, 'You submitted this proof for the employee, so someone else verifies it.');
    $this->actingAs($this->checker);
    app(InvestmentProofs::class)->review($item, 'verified', 45000, null, $this->checker);
    expect($item->refresh()->proof_status)->toBe('verified');
});

// ---- Request types and the launcher links ----------------------------------------------------------------------------

it('lists the on-behalf forms on the request types, gated by their permission, each opening directly by URL', function () {
    $hr = tenantUser($this->tenant, ['payroll.manage', 'payroll.view', 'expense.approve', 'expense.view', 'exit.manage', 'exit.view']);
    $this->actingAs($hr);
    $registry = tap(app(RequestRegistry::class))->forget();
    $urls = collect($registry->onBehalf($hr))->mapWithKeys(fn (array $e) => [$e['type']->key() => $e['url']]);
    $expected = [
        'reimbursement' => ReimbursementClaimResource::getUrl('index').'?action=claimForEmployee',
        'investment_declaration' => DeclareInvestments::getUrl().'?action=declareForEmployee',
        'investment_proof' => TaxDeclarationResource::getUrl('index').'?action=submitProofForEmployee',
        'expense_advance' => ExpenseAdvanceResource::getUrl('index').'?action=requestForEmployee',
        'resignation' => ExitCaseResource::getUrl('index').'?action=initiate',
    ];
    foreach ($expected as $key => $url) {
        expect($urls[$key] ?? null)->toBe($url);
        $this->get($url)->assertOk();
    }
    expect(collect($expected)->keys()->map(fn (string $key) => $registry->find($key)->onBehalfPermission())->all())
        ->toBe(['payroll.manage', 'payroll.manage', 'payroll.manage', 'expense.approve', 'exit.manage']);

    // Each form mounts on its page for HR.
    Livewire::test(ListReimbursementClaims::class)->mountAction('claimForEmployee')->assertActionMounted('claimForEmployee');
    Livewire::test(DeclareInvestments::class)->mountAction('declareForEmployee')->assertActionMounted('declareForEmployee');
    Livewire::test(ManageTaxDeclarations::class)->mountAction('submitProofForEmployee')->assertActionMounted('submitProofForEmployee');
    Livewire::test(ListExpenseAdvances::class)->mountAction('requestForEmployee')->assertActionMounted('requestForEmployee');
    Livewire::test(ListExitCases::class)->mountAction('initiate')->assertActionMounted('initiate')->assertSchemaStateSet(['type' => 'resignation']);

    // "Raise for someone" on New request (exit.manage alone opens it now) and the payroll desk / actions menu link them.
    $this->get(NewRequest::getUrl())->assertOk()->assertSee('?action=claimForEmployee', false)->assertSee('?action=initiate', false);
    $this->actingAs(tenantUser($this->tenant, ['exit.manage', 'exit.view']));
    expect(NewRequest::canAccess())->toBeTrue();
    $this->actingAs($hr);
    $tax = collect(collect((new PayrollDesk)->deskSections())->firstWhere('label', 'Tax & benefit')['items'])->pluck('url', 'label');
    expect($tax['Claim a reimbursement for an employee'])->toBe(ReimbursementClaimResource::getUrl('index', ['action' => 'claimForEmployee']))
        ->and($tax['Declare investments for an employee'])->toBe(DeclareInvestments::getUrl(['action' => 'declareForEmployee']))
        ->and($tax['Submit an investment proof for an employee'])->toBe(TaxDeclarationResource::getUrl('index', ['action' => 'submitProofForEmployee']));
    $menu = collect(PayrollRegisters::actionsMenu()->getActions())->filter(fn (Action $a) => $a->isVisible())->map(fn (Action $a) => $a->getName())->values()->all();
    expect($menu)->toContain('posPayClaim', 'posPayInvestments', 'posPayProof');

    // Without the permissions the types offer nothing on behalf.
    $this->actingAs($this->user);
    $registry->forget();
    expect(collect($registry->onBehalf($this->user))->map(fn (array $e) => $e['type']->key())->intersect(array_keys($expected))->all())->toBe([]);
});
