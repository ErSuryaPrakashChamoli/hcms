<?php

use App\Domain\Compliance\Imports\OutsideIncomeImports;
use App\Domain\Compliance\Imports\PerquisiteImports;
use App\Domain\Compliance\Imports\PfOptionImports;
use App\Domain\Compliance\Imports\StatutoryOverrideImports;
use App\Domain\Compliance\Models\EmployeeTaxDeclaration;
use App\Domain\Compliance\Models\StatutoryOverride;
use App\Domain\Compliance\Models\TaxIncomeEntry;
use App\Domain\Compliance\Models\TdsEmployeeInvestment;
use App\Domain\Compliance\Services\InvestmentDeclarations;
use App\Domain\Compliance\Services\InvestmentProofs;
use App\Domain\Compliance\Services\PfOptions;
use App\Domain\Compliance\Services\StatutoryOverrides;
use App\Domain\Compliance\Services\TaxIncomeEntries;
use App\Domain\Configuration\Services\SetupCatalogue;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Services\PayrollCalculator;
use App\Domain\Payroll\Services\PayrollComputation;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Domain\Platform\Services\SettingsRepository;
use App\Http\Controllers\Form12bbController;
use App\Http\Controllers\ParityFileController;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/* HROne parity HP-3: tax and statutory — proofs feed TDS, other heads, perquisites and outside income, overrides, VPF, NPS, 12BB, uploads. */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->approver = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->company = payrollCompany();
    $this->period = fn (int $month = 9) => PayrollPeriod::for($this->company, 2026, $month);
    $this->tax = fn (Employee $employee, int $month = 9): PayrollComputation => app(PayrollCalculator::class)->calculate($employee->refresh(), ($this->period)($month));
    $this->declare = fn (Employee $employee, array $input, $user = null, bool $submit = true) => app(InvestmentDeclarations::class)->save($user ?? $this->hr, $employee, '2026-27', $input + ['regime' => 'old', 'items' => []], $submit);
    $this->setting = fn (string $key, mixed $value) => app(SettingsRepository::class)->set($key, $value, 'test');
});

function taxedEmployee(float $ctc, array $permissions = ['payroll.payslip']): Employee
{
    $employee = salariedEmployee($ctc, $permissions, from: '2026-01-01');
    EmployeeStatutoryDetail::create(['employee_id' => $employee->id, 'pan' => 'ABCDE'.random_int(1000, 9999).'F']);

    return $employee->refresh();
}

function stagePayrollRows(RowImports $service, string $csv): EmployeeImport
{
    $path = EmployeeImports::directory().'/'.uniqid().'.csv';
    Storage::disk('local')->put($path, $csv);
    $import = $service->register($path, 'upload.csv', auth()->user());
    $service->inspect($import);
    $service->map($import->refresh(), $import->refresh()->mapping);
    $service->validate($import->refresh());
    $service->approve($import->refresh(), auth()->user(), 'Go-live');

    return $service->run($import->refresh(), auth()->user());
}

it('counts perquisites and income paid outside payroll in the TDS projection, with the tax already deducted on it', function () {
    $employee = taxedEmployee(2400000);
    $base = ($this->tax)($employee)->inputs['tax'];
    $entries = app(TaxIncomeEntries::class);

    $entries->recordPerquisite($employee, '2026-27', 'car', 'Company car', 100000, 20000, $this->hr);
    $outside = $entries->recordOutside($employee, '2026-05-31', 'Salary April–May on the old system', 200000, 200000, 30000, 0, 0, $this->hr);
    $tax = ($this->tax)($employee)->inputs['tax'];

    expect($tax['taxable_income'])->toBe($base['taxable_income'] + 280000)
        ->and($tax['perquisites'])->toBe(80000.0)
        ->and($tax['outside_payroll_taxable'])->toBe(200000.0)
        ->and($tax['already_deducted'])->toBe(30000.0)
        ->and($outside->financial_year)->toBe('2026-27')
        ->and(array_key_exists('perquisites', $base))->toBeFalse(); // earlier bases stay as they were

    $entries->cancel($outside, 'Entered twice', $this->hr);
    expect(($this->tax)($employee)->inputs['tax']['taxable_income'])->toBe($base['taxable_income'] + 80000);

    expect(fn () => $entries->recordPerquisite($employee, '2026-27', 'car', 'Car', 1000, 2000, $this->hr))->toThrow(RuntimeException::class, 'recovered')
        ->and(fn () => $entries->recordPerquisite($employee, '2026-27', 'yacht', 'Boat', 1000, 0, $this->hr))->toThrow(RuntimeException::class, 'type')
        ->and(fn () => $entries->recordOutside($employee, '2026-05-31', 'Bonus', 1000, 1000, 0, 0, 0, tenantUser($this->tenant, ['payroll.view'])))->toThrow(RuntimeException::class, 'Only payroll');
});

it('uses verified proof amounts for TDS from the organisation\'s proof date, and takes proofs from employees only in the proof window', function () {
    $employee = taxedEmployee(1800000);
    ($this->declare)($employee, ['items' => ['80C.ppf' => ['amount' => 100000], '80D.self_family' => ['amount' => 20000]]]);
    $c = ($this->tax)($employee);
    $pf = $c->amount('PF_EE') * 7;
    expect($c->inputs['tax']['chapter_via'])->toBe(round(100000 + $pf + 20000, 2));

    ($this->setting)('payroll.tax.verified_proofs_from', '2026-09-01');
    $tax = ($this->tax)($employee)->inputs['tax'];
    expect($tax['chapter_via'])->toBe(round($pf, 2))->and($tax['amounts_basis'])->toBe('verified_proofs');

    $ppf = TdsEmployeeInvestment::query()->where('item_key', '80C.ppf')->firstOrFail();
    $health = TdsEmployeeInvestment::query()->where('item_key', '80D.self_family')->firstOrFail();
    $proofs = app(InvestmentProofs::class);
    $proofs->review($ppf, 'verified', 60000, null, $this->approver);
    expect(($this->tax)($employee)->inputs['tax']['chapter_via'])->toBe(round(60000 + $pf, 2));

    // The employee submits proofs only in the window, and only their own.
    ($this->setting)('payroll.tax.proof_from', '2026-10-01');
    $user = $employee->user;
    expect(fn () => $proofs->submit($health, ['investment-proofs/a.pdf'], 20000, $user))->toThrow(RuntimeException::class, 'Proofs can be submitted from 1 Oct 2026');
    $this->travelTo('2026-10-02 10:00:00');
    $proofs->submit($health, ['investment-proofs/a.pdf'], 18000, $user);
    expect($health->refresh()->proof_claimed_amount)->toEqual('18000.00')->and($health->proof_submitted_at)->not->toBeNull();

    $proofs->review($health, 'rejected', 0, 'Receipt is unreadable', $this->approver);
    $proofs->submit($health->refresh(), ['investment-proofs/b.pdf'], 18000, $user);
    expect($health->refresh()->proof_status)->toBe('pending')->and($health->proof_files)->toBe(['investment-proofs/b.pdf'])
        ->and(fn () => $proofs->submit($ppf->refresh(), ['x.pdf'], 1, $user))->toThrow(RuntimeException::class, 'verified this proof already')
        ->and(fn () => $proofs->review($health, 'verified', 18000, null, $user))->toThrow(RuntimeException::class);

    $other = taxedEmployee(900000);
    expect(fn () => $proofs->submit($health, ['x.pdf'], 1, $other->user))->toThrow(RuntimeException::class, 'only for your own');
});

it('closes declarations outside their window, locks the regime once submitted and asks for every landlord\'s PAN when configured', function () {
    $employee = taxedEmployee(1200000);
    $user = $employee->user;

    ($this->setting)('payroll.tax.declaration_to', '2026-09-01');
    expect(fn () => ($this->declare)($employee, [], $user))->toThrow(RuntimeException::class, 'Declarations can be changed until 1 Sep 2026');
    ($this->declare)($employee, []); // payroll always may
    expect(app(InvestmentDeclarations::class)->canEdit($user, $employee, EmployeeTaxDeclaration::query()->first()))->toBeFalse();

    ($this->setting)('payroll.tax.declaration_to', null);
    ($this->setting)('payroll.tax.regime_locked_after_submit', true);
    expect(fn () => ($this->declare)($employee, ['regime' => 'new'], $user))->toThrow(RuntimeException::class, 'regime cannot be changed');
    ($this->declare)($employee, ['regime' => 'old'], $user);

    ($this->setting)('payroll.tax.landlord_pan_required', true);
    ($this->setting)('payroll.tax.max_landlords', 2);
    $rent = fn (array $details) => ['items' => ['HRA_RENT.rent' => ['amount' => 240000, 'details' => ['monthly_rent' => 20000, 'months' => 12, 'landlord_name' => 'R Gupta'] + $details]]];
    expect(fn () => ($this->declare)($employee, $rent([]), $user))->toThrow(RuntimeException::class, 'PAN of every landlord')
        ->and(fn () => ($this->declare)($employee, $rent(['landlord_pan' => 'ABCDE1234F', 'landlords' => [['name' => 'S Gupta', 'pan' => 'ABCDE1234G'], ['name' => 'T Gupta', 'pan' => 'ABCDE1234H']]]), $user))
        ->toThrow(RuntimeException::class, 'at most 2 landlord');
    ($this->declare)($employee, $rent(['landlord_pan' => 'ABCDE1234F', 'landlords' => [['name' => 'S Gupta', 'pan' => 'ABCDE1234G']]]), $user);
    expect(TdsEmployeeInvestment::query()->where('item_key', 'HRA_RENT.rent')->firstOrFail()->details['landlords'])->toHaveCount(1);
});

it('counts other income and the previous employer\'s PF and PT, and holds home-loan interest until the rule carries its limit', function () {
    $employee = taxedEmployee(1800000);
    ($this->declare)($employee, []);
    $base = ($this->tax)($employee)->inputs['tax'];

    ($this->declare)($employee, [
        'items' => ['OTHER_INCOME.deposit_interest' => ['amount' => 50000], '24B.self_occupied' => ['amount' => 150000]],
        'previous_employer_income' => 100000, 'previous_employer_tds' => 5000,
        'previous_employer' => ['name' => 'Acme Ltd', 'tan' => 'abcd12345e', 'pf' => 7200, 'pt' => 1200],
    ]);
    $tax = ($this->tax)($employee)->inputs['tax'];

    // + previous salary + other income − previous PT (old regime) − previous PF (80C, under the limit).
    expect($tax['taxable_income'])->toBe($base['taxable_income'] + 100000 + 50000 - 1200 - 7200)
        ->and($tax['other_income'])->toBe(50000.0)
        ->and($tax['house_property_not_counted'])->toBe(150000.0)
        ->and(array_key_exists('house_property_loss', $tax))->toBeFalse()
        ->and($tax['already_deducted'])->toBe(5000.0)
        ->and(EmployeeTaxDeclaration::query()->firstOrFail()->previousEmployer('tan'))->toBe('ABCD12345E');

    $counting = app(InvestmentDeclarations::class)->counting('2026-27', 'old');
    expect($counting['24B']['allowed'])->toBeFalse()->and($counting['OTHER_INCOME']['allowed'])->toBeTrue()->and($counting['80C']['allowed'])->toBeTrue();
});

it('replaces a calculated statutory line with an audited override, and refuses it on one\'s own pay, without the permission or once finalised', function () {
    $employee = taxedEmployee(1200000);
    EmployeeBankAccount::create(['employee_id' => $employee->id, 'account_holder_name' => 'X', 'bank_name' => 'HDFC', 'account_number' => '1234567890', 'ifsc' => 'HDFC0000001', 'is_primary' => true]);
    $overrides = app(StatutoryOverrides::class);
    $september = ($this->period)(9);
    expect(($this->tax)($employee)->has('TDS'))->toBeFalse(); // under the rebate

    $overrides->set($employee, $september, 'TDS', 1234, 'Employee asked for extra TDS', $this->hr);
    $overrides->set($employee, $september, 'PT', 0, 'Exempt: disability certificate', $this->hr);
    $c = ($this->tax)($employee);
    expect($c->amount('TDS'))->toBe(1234.0)
        ->and($c->has('PT'))->toBeFalse()
        ->and($c->inputs['statutory_overrides']['PT']['computed'])->toBe(200.0)
        ->and(collect($c->lines)->firstWhere('code', 'TDS')['basis']['override']['computed'])->toBe(0.0)
        ->and(collect($c->exceptions)->pluck('type')->all())->toContain('statutory_override');

    $overrides->set($employee, $september, 'TDS', 2000, 'Revised', $this->hr);
    expect(($this->tax)($employee)->amount('TDS'))->toBe(2000.0)
        ->and(StatutoryOverride::query()->where('code', 'TDS')->pluck('status')->sort()->values()->all())->toBe(['active', 'cancelled']);

    expect(fn () => $overrides->set($employee, $september, 'TDS', 1, 'x', tenantUser($this->tenant, ['payroll.manage'])))->toThrow(RuntimeException::class, 'cannot override')
        ->and(fn () => $overrides->set($employee, $september, 'BONUS', 1, 'x', $this->hr))->toThrow(RuntimeException::class, 'from the list');
    $self = taxedEmployee(900000, ['payroll.override']);
    expect(fn () => $overrides->set($self, $september, 'TDS', 1, 'x', $self->user))->toThrow(RuntimeException::class, 'your own');

    EmployeeBankAccount::create(['employee_id' => $self->id, 'account_holder_name' => 'Y', 'bank_name' => 'HDFC', 'account_number' => '1234567891', 'ifsc' => 'HDFC0000001', 'is_primary' => true]);
    $runs = app(PayrollRuns::class);
    $run = $runs->calculate($runs->open($this->company, 2026, 9, $this->hr), $this->hr);
    $runs->finalize($runs->approve($runs->validate($run), $this->approver, 'ok'), $this->approver);
    expect(fn () => $overrides->set($employee, $september, 'TDS', 1, 'Late change', $this->hr))->toThrow(RuntimeException::class, 'finalised');
});

it('deducts voluntary PF on top of the statutory share, counts it under 80C and applies the employee\'s PF wage basis', function () {
    $employee = taxedEmployee(1200000);
    ($this->declare)($employee, []);
    $before = ($this->tax)($employee);
    expect($before->amount('PF_EE'))->toBe(3000.0)->and($before->has('VPF'))->toBeFalse();

    app(PfOptions::class)->set($employee, '2026-09-01', 'percent', 10, 'actual', 'Employee request', $this->hr);
    $after = ($this->tax)($employee);
    expect($after->amount('PF_EE'))->toBe(4800.0)       // 12% of actual PF wages (basic 40,000), not the ceiling
        ->and($after->amount('VPF'))->toBe(4000.0)      // 10% of PF wages
        ->and($after->inputs['tax']['chapter_via'])->toBe(round((4800 + 4000) * 7, 2));

    $later = taxedEmployee(1200000);
    app(PfOptions::class)->set($later, '2026-10-01', 'amount', 2500, null, 'From October', $this->hr);
    expect(($this->tax)($later)->has('VPF'))->toBeFalse()->and(($this->tax)($later, 10)->amount('VPF'))->toBe(2500.0);
    expect(fn () => app(PfOptions::class)->set($later, '2026-11-01', 'percent', 120, null, 'x', $this->hr))->toThrow(RuntimeException::class, 'up to 100');
});

it('counts an employer NPS component as salary and under 80CCD(2)', function () {
    $nps = SalaryComponent::create(['name' => 'NPS (employer)', 'code' => 'NPS_ER', 'type' => 'employer_contribution', 'classification' => 'nps_employer', 'calculation_method' => 'fixed',
        'taxable' => false, 'include_in_ctc' => true, 'include_in_gross' => false, 'is_recurring' => true, 'is_proratable' => false, 'sort_order' => 705, 'status' => 'active']);
    approveStructureVersion('STANDARD', '2026-01-01', [$nps->id => 60]);
    $employee = salariedEmployee(2400000, from: '2026-01-01', componentValues: ['CONV' => 1600, 'NPS_ER' => 5000]);

    $tax = ($this->tax)($employee)->inputs['tax'];
    expect($tax['employer_nps'])->toBe(35000.0)->and($tax['chapter_via'])->toBe(35000.0); // new regime: 80CCD(2) only
});

it('prints Form 12BB and serves proof files only through signed links to the owner and payroll', function () {
    $employee = taxedEmployee(1200000);
    ($this->setting)('payroll.tax.max_landlords', 2);
    ($this->declare)($employee, ['items' => [
        'HRA_RENT.rent' => ['amount' => 240000, 'details' => ['monthly_rent' => 20000, 'months' => 12, 'landlord_name' => 'R Gupta', 'landlord_pan' => 'ABCDE1234F', 'landlords' => [['name' => 'S Gupta', 'pan' => 'ABCDE1234G']]]],
        '80C.ppf' => ['amount' => 50000],
    ]]);
    $declaration = EmployeeTaxDeclaration::query()->firstOrFail();

    $this->get(Form12bbController::url($declaration))->assertOk()->assertSee('FORM NO. 12BB')->assertSee('S Gupta')->assertSee('ABCDE1234G')->assertSee('Public Provident Fund');
    $this->get(route('tax.form-12bb', ['declaration' => $declaration->id]))->assertForbidden(); // unsigned

    $ppf = TdsEmployeeInvestment::query()->where('item_key', '80C.ppf')->firstOrFail();
    Storage::disk('local')->put('investment-proofs/ppf.pdf', 'pdf');
    $ppf->update(['proof_files' => ['investment-proofs/ppf.pdf']]);
    $url = ParityFileController::url('investment-proof', $ppf, 0);

    $this->actingAs($employee->user)->get($url)->assertOk()->assertDownload('ppf.pdf');
    $this->actingAs($employee->user)->get(Form12bbController::url($declaration))->assertOk();
    $stranger = taxedEmployee(900000);
    $this->actingAs($stranger->user)->get($url)->assertForbidden();
    $this->actingAs($stranger->user)->get(Form12bbController::url($declaration))->assertForbidden();
    $this->actingAs($this->hr)->get(ParityFileController::url('investment-proof', $ppf, 3))->assertNotFound();
});

it('uploads perquisites, income outside payroll, statutory overrides and PF options through the staged pipeline', function () {
    $employee = taxedEmployee(1200000);
    $code = $employee->employee_code;

    $perqs = stagePayrollRows(app(PerquisiteImports::class), "Employee code,Financial year,Perquisite type,Description,Value for the year,Recovered from the employee\n{$code},2026-27,Cars / other automotive,Car,43200,3200\n{$code},2026-27,yacht,Boat,1000,0\n");
    $outside = stagePayrollRows(app(OutsideIncomeImports::class), "Employee code,Paid on,Description,Amount paid,Income tax deducted\n{$code},2026-05-31,Old system salary,150000,6000\n");
    $overrides = stagePayrollRows(app(StatutoryOverrideImports::class), "Employee code,Payroll month,Line,Amount,Reason\n{$code},2026-09,PT,0,Exempt\n{$code},2026-09,PT,10,Twice\n");
    $pf = stagePayrollRows(app(PfOptionImports::class), "Employee code,From,VPF mode,VPF value,Reason\n{$code},2026-09-01,amount,1500,Employee request\n");

    expect($perqs->error_count)->toBe(1)
        ->and(TaxIncomeEntry::query()->where('kind', 'perquisite')->value('taxable_amount'))->toEqual('40000.00')
        ->and(TaxIncomeEntry::query()->where('kind', 'outside_payroll')->value('taxable_amount'))->toEqual('150000.00')
        ->and($overrides->error_count)->toBe(1)
        ->and(StatutoryOverride::query()->active()->where('code', 'PT')->value('amount'))->toEqual('0.00')
        ->and($pf->error_count)->toBe(0);

    $c = ($this->tax)($employee);
    expect($c->amount('VPF'))->toBe(1500.0)->and($c->has('PT'))->toBeFalse()
        ->and($c->inputs['tax']['perquisites'])->toBe(40000.0)->and($c->inputs['tax']['outside_payroll_tds'])->toBe(6000.0)
        ->and($outside->error_count)->toBe(0);
});

it('saves organisation tax dates from the Setup centre and rejects anything that is not a date', function () {
    $catalogue = app(SetupCatalogue::class);
    $field = SetupCatalogue::field('payroll.tax.verified_proofs_from');

    expect($catalogue->save('payroll', [$field => '2027-01-15'], 'Proofs due in January', $this->hr))->toBe(['payroll.tax.verified_proofs_from'])
        ->and(app(SettingsRepository::class)->get('payroll.tax.verified_proofs_from'))->toBe('2027-01-15')
        ->and(fn () => $catalogue->save('payroll', [$field => 'soon'], 'x', $this->hr))->toThrow(RuntimeException::class, 'must be a date');
});
