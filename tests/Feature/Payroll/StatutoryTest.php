<?php

use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Models\EmployeeTaxDeclaration;
use App\Domain\Compliance\Services\ComplianceRules;
use App\Domain\Compliance\Services\FinancialYear;
use App\Domain\Compliance\Services\TaxComputer;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Services\PayrollCalculator;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/PayrollTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['*']));
    $this->company = payrollCompany();
    $this->calc = fn ($employee, int $year = 2026, int $month = 9) => app(PayrollCalculator::class)->calculate($employee, PayrollPeriod::for($this->company, $year, $month));
});

it('loads versioned statutory rules from the platform pack and resolves by date and state', function () {
    $rules = app(ComplianceRules::class);

    expect(ComplianceRule::query()->count())->toBeGreaterThan(15)
        ->and($rules->resolve('EPF', '2026-09-30')->param('employee_rate'))->toBe(0.12)
        ->and($rules->resolve('PT', '2026-09-30', 'KA')->param('slabs'))->toBe([[24999, 0], [null, 200]])
        ->and($rules->resolve('PT', '2026-09-30', 'XX'))->toBeNull()
        ->and($rules->resolve('TDS', '2026-03-31')->version)->toBe(1)
        ->and($rules->resolve('TDS', '2026-04-01')->version)->toBe(3); // Phase 7: v3 corrects v2 (Income-tax Act, 2025)

    // Re-syncing is idempotent.
    syncComplianceRules();
    expect(ComplianceRule::query()->where('code', 'EPF')->count())->toBe(2); // v1 (2014) and v2 (17 Sep 2026)
});

it('splits CTC into components and applies EPF at the wage ceiling, PT by state slab and TDS by projection', function () {
    $employee = salariedEmployee(600000);
    // August 2026: before the EPF wage-ceiling change of 17 Sep 2026 (EPF v1, ceiling 15,000).
    $c = ($this->calc)($employee, 2026, 8);

    expect($c->amount('BASIC'))->toBe(20000.0)
        ->and($c->amount('HRA'))->toBe(10000.0)
        ->and($c->amount('CONV'))->toBe(1600.0)
        ->and($c->amount('SPECIAL'))->toBe(16600.0)      // balances to CTC net of employer PF
        ->and($c->gross())->toBe(48200.0)
        ->and($c->amount('PF_EE'))->toBe(1800.0)          // 12% of 15,000 ceiling
        ->and($c->amount('PF_ER'))->toBe(1800.0)
        ->and($c->amount('PT'))->toBe(200.0)              // Karnataka: above 24,999
        ->and($c->has('ESI_EE'))->toBeFalse()             // above the 21,000 ESI ceiling
        ->and($c->amount('TDS'))->toBeGreaterThan(0)
        ->and($c->inputs['tax']['regime'])->toBe('new')
        ->and($c->net())->toBe(round(48200 - 1800 - 200 - $c->amount('TDS'), 2))
        ->and(collect($c->exceptions)->pluck('type')->all())->toContain('no_pan', 'no_bank');
});

it('applies ESI on low wages, exempts EPF above the ceiling when configured, and skips statutes the entity opts out of', function () {
    $low = salariedEmployee(240000); // 20,000 monthly
    $c = ($this->calc)($low);

    expect($c->gross())->toBe(19040.0) // 8,000 + 4,000 + 1,600 + special 5,440 (CTC less employer PF 960)
        ->and($c->amount('ESI_EE'))->toBe(ceil(19040 * 0.0075))
        ->and($c->amount('ESI_ER'))->toBe(ceil(19040 * 0.0325))
        ->and($c->amount('PT'))->toBe(0.0); // Karnataka: below 25,000

    payrollCompany(['pf_restrict_to_ceiling' => false, 'esi_applicable' => false, 'pt_applicable' => false, 'tds_applicable' => false]);
    $high = salariedEmployee(1200000);
    $c = ($this->calc)($high);

    expect($c->amount('PF_EE'))->toBe(round(40000 * 0.12))
        ->and($c->has('ESI_EE'))->toBeFalse()->and($c->has('PT'))->toBeFalse()->and($c->has('TDS'))->toBeFalse();
});

it('uses the Maharashtra February amount, the female exemption and LWF in contribution months', function () {
    payrollCompany(['pt_state' => 'MH', 'lwf_state' => 'MH', 'lwf_applicable' => true]);
    $employee = salariedEmployee(360000); // gross 28,560
    $employee->person->update(['gender' => 'male']);

    expect(($this->calc)($employee, 2026, 9)->amount('PT'))->toBe(200.0)
        ->and(($this->calc)($employee, 2026, 2)->amount('PT'))->toBe(300.0)
        ->and(($this->calc)($employee, 2026, 9)->has('LWF_EE'))->toBeFalse()
        ->and(($this->calc)($employee, 2026, 6)->amount('LWF_EE'))->toBe(25.0)
        ->and(($this->calc)($employee, 2026, 6)->amount('LWF_ER'))->toBe(75.0);

    $employee->person->update(['gender' => 'female']);
    $woman = salariedEmployee(240000); // gross 19,040 ≤ 25,000
    $woman->person->update(['gender' => 'female']);
    expect(($this->calc)($woman)->amount('PT'))->toBe(0.0);
});

it('computes income tax per regime with rebate, chapter VI-A limits and the no-PAN rate', function () {
    $tax = app(TaxComputer::class);
    $rule = app(ComplianceRules::class)->resolve('TDS', '2026-09-30');

    // Pure slab arithmetic: new regime on 20 lakh = 20,000 + 40,000 + 60,000 + 80,000 = 2,00,000.
    expect($tax->slabTax($rule->param('new.slabs'), 2000000))->toBe(200000.0)
        ->and($tax->slabTax($rule->param('old.slabs'), 1000000))->toBe(112500.0);

    // 12 lakh CTC → taxable well under the 12 lakh rebate limit → zero TDS in the new regime.
    $employee = salariedEmployee(1200000);
    EmployeeStatutoryDetail::create(['employee_id' => $employee->id, 'pan' => 'ABCDE1234F']);
    $c = ($this->calc)($employee->refresh());
    expect($c->has('TDS'))->toBeFalse()->and($c->inputs['tax']['rebate'])->toBeGreaterThan(0);

    // Old regime with 80C declared: standard deduction 50,000, 80C capped at 1.5 lakh, PF counted.
    EmployeeTaxDeclaration::create(['employee_id' => $employee->id, 'financial_year' => app(FinancialYear::class)->label('2026-09-30'), 'regime' => 'old', 'declarations' => ['80C' => 500000, '80D' => 10000], 'status' => 'verified']);
    $c = ($this->calc)($employee->refresh());
    // Projection: 98,200 × 7 remaining months = 6,87,400 − 50,000 − PT 1,400 − VI-A 1,60,000 = 4,76,000 → 5% over 2.5 lakh = 11,300, wiped by the 87A rebate.
    expect($c->inputs['tax']['regime'])->toBe('old')
        ->and($c->inputs['tax']['chapter_via'])->toBe(160000.0)
        ->and($c->inputs['tax']['standard_deduction'])->toBe(50000.0)
        ->and($c->inputs['tax']['taxable_income'])->toBe(467600.0) // Phase 7: September 2026 uses draft EPF v2 (ceiling 25,000): smaller special allowance, larger PF in 80C
        ->and($c->inputs['tax']['tax_before_rebate'])->toBe(10880.0) // Phase 7: lower taxable income under draft EPF v2 in September 2026
        ->and($c->has('TDS'))->toBeFalse();

    // Same declarations, three times the pay: rebate gone, TDS deducted.
    $rich = salariedEmployee(3600000);
    EmployeeStatutoryDetail::create(['employee_id' => $rich->id, 'pan' => 'ABCDE1234G']);
    EmployeeTaxDeclaration::create(['employee_id' => $rich->id, 'financial_year' => app(FinancialYear::class)->label('2026-09-30'), 'regime' => 'old', 'declarations' => ['80C' => 500000], 'status' => 'verified']);
    $c = ($this->calc)($rich->refresh());
    expect($c->amount('TDS'))->toBeGreaterThan(0)->and($c->inputs['tax']['cess'])->toBeGreaterThan(0);

    // No PAN: at least 20% of taxable income, flagged as an exception.
    $noPan = salariedEmployee(3000000);
    $c = ($this->calc)($noPan);
    expect($c->inputs['tax']['annual_tax'])->toBeGreaterThanOrEqual(round($c->inputs['tax']['taxable_income'] * 0.20))
        ->and(collect($c->exceptions)->pluck('type')->all())->toContain('no_pan');
});

it('protects statutory components and reserved codes from tenant changes', function () {
    $pf = SalaryComponent::create(['name' => 'PF employee', 'code' => 'PF_EE', 'type' => 'deduction', 'classification' => 'pf_employee', 'calculation_method' => 'statutory', 'is_statutory' => true]);

    expect(fn () => $pf->update(['formula' => 'basic * 0.05']))->toThrow(RuntimeException::class, 'compliance pack');
    $pf->refresh()->update(['name' => 'Provident fund']); // cosmetic edits are fine
    expect($pf->refresh()->name)->toBe('Provident fund');
    expect(auth()->user()->can('delete', $pf))->toBeFalse();
});
