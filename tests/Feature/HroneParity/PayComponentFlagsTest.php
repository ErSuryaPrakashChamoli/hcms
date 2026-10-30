<?php

use App\Domain\Claims\Services\ReimbursementClaims;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Services\PayrollCalculator;
use App\Domain\Payroll\Services\PayrollComputation;
use App\Domain\Payroll\Services\VariablePay;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/* HROne setup parity PS-1: pay component flags — statutory wages, joining / exit months, rounding, payslip display, tax by regime, claims, nature. */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->company = payrollCompany();
    $this->calc = fn ($employee, int $month = 9): PayrollComputation => app(PayrollCalculator::class)->calculate($employee->refresh(), PayrollPeriod::for($this->company, 2026, $month));
    $this->component = fn (string $code) => SalaryComponent::query()->where('code', $code)->firstOrFail();
});

it('takes professional tax on PT wages when components are flagged out of them', function () {
    $employee = salariedEmployee(400000, from: '2026-01-01');
    expect(($this->calc)($employee)->amount('PT'))->toBe(200.0);

    SalaryComponent::query()->whereIn('code', ['HRA', 'SPECIAL'])->update(['pt_wages' => false]);
    $c = ($this->calc)($employee);
    expect($c->ptWages())->toBeLessThan(25000.0)->and($c->has('PT'))->toBeFalse()
        ->and($c->gross())->toBeGreaterThan(25000.0); // gross itself is unchanged
});

it('skips components in the joining month, rounds them, and hides them from the payslip', function () {
    ($this->component)('CONV')->update(['pay_in_joining_month' => false]);
    ($this->component)('HRA')->update(['rounding' => 'nearest_10', 'show_on_payslip' => false]);
    $joiner = salariedEmployee(612345, from: '2026-01-01');
    $joiner->update(['joining_date' => '2026-09-10']);

    $september = ($this->calc)($joiner);
    $hra = collect($september->lines)->firstWhere('code', 'HRA');
    expect($september->has('CONV'))->toBeFalse()
        ->and(fmod($hra['amount'], 10.0))->toBe(0.0)->and($hra['basis']['hidden_on_payslip'])->toBeTrue()
        ->and(($this->calc)($joiner, 10)->has('CONV'))->toBeTrue();
});

it('taxes a non-taxable component in the regimes where it is not exempt', function () {
    $meal = SalaryComponent::create(['name' => 'Meal card', 'code' => 'MEAL', 'type' => 'earning', 'classification' => 'allowance', 'calculation_method' => 'fixed', 'taxable' => false, 'exempt_in_regimes' => ['old'],
        'include_in_ctc' => true, 'include_in_gross' => true, 'is_recurring' => true, 'is_proratable' => false, 'sort_order' => 40, 'status' => 'active']);
    approveStructureVersion('STANDARD', '2026-01-01', [$meal->id => 45]);
    $employee = salariedEmployee(1800000, from: '2026-01-01', componentValues: ['CONV' => 1600, 'MEAL' => 2200]);
    EmployeeStatutoryDetail::create(['employee_id' => $employee->id, 'pan' => 'ABCDE1234F']);

    $c = ($this->calc)($employee);
    expect($c->inputs['tax']['this_month_taxable'])->toBe(round($c->taxableEarnings() + 2200, 2)); // new regime: the meal card is taxed
});

it('applies per-component claim rules and keeps variable pay to variable components', function () {
    $fuel = SalaryComponent::create(['name' => 'Fuel', 'code' => 'FUEL', 'type' => 'reimbursement', 'classification' => 'reimbursement', 'calculation_method' => 'fixed', 'taxable' => false, 'include_in_ctc' => true,
        'include_in_gross' => false, 'is_recurring' => true, 'is_proratable' => false, 'pay_on_claim' => true, 'claim_attachment_required' => false, 'claim_window_days' => 30, 'claim_limit_per_claim' => 1000, 'sort_order' => 50, 'status' => 'active']);
    approveStructureVersion('STANDARD', '2026-01-01', [$fuel->id => 50]);
    $employee = salariedEmployee(600000, from: '2026-01-01', componentValues: ['CONV' => 1600, 'FUEL' => 2000]);
    $claims = app(ReimbursementClaims::class);
    app(\App\Domain\Platform\Services\SettingsRepository::class)->set('payroll.claims.allow_beyond_balance', true, 'test');

    expect(fn () => $claims->claim($employee, $fuel, 1500, '2026-09-20', 'Fuel'))->toThrow(RuntimeException::class, 'up to 1,000.00 at a time')
        ->and(fn () => $claims->claim($employee, $fuel, 500, '2026-07-01', 'Fuel'))->toThrow(RuntimeException::class, 'from the last 30 day(s)')
        ->and($claims->claim($employee, $fuel, 500, '2026-09-20', 'Fuel')->status)->toBe('pending'); // no bills needed for this component

    $bonus = SalaryComponent::query()->where('code', 'BONUS')->firstOrFail();
    $incentive = SalaryComponent::create(['name' => 'Sales incentive', 'code' => 'INCENT', 'type' => 'earning', 'classification' => 'incentive', 'calculation_method' => 'fixed', 'pay_nature' => 'variable', 'taxable' => true,
        'include_in_ctc' => false, 'include_in_gross' => false, 'is_recurring' => false, 'sort_order' => 110, 'status' => 'active']);
    expect(fn () => app(VariablePay::class)->request($employee, $bonus, 5000, '2026-10', 'Quarter'))->toThrow(RuntimeException::class, 'fixed component')
        ->and(app(VariablePay::class)->request($employee, $incentive, 5000, '2026-10', 'Quarter')->status)->toBe('pending');
});
