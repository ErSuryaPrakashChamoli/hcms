<?php

use App\Domain\Claims\Services\ReimbursementClaims;
use App\Domain\Compliance\Models\EmployeeTaxDeclaration;
use App\Domain\Compliance\Models\TdsCertificate;
use App\Domain\Compliance\Services\InvestmentDeclarations;
use App\Domain\Compliance\Services\TaxEstimator;
use App\Domain\Compliance\Services\TaxSettings;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Resources\MyTaxCertificates\MyTaxCertificateResource;
use App\Http\Controllers\Form12bbController;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/* HROne setup parity PS-2: tax and investment switches — day window, approver edits, Form 12BB, estimator, Form 16, bills under the new regime. */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    payrollCompany();
    $this->employee = salariedEmployee(1200000, ['payroll.payslip', 'payroll.request'], from: '2026-01-01');
    $this->set = fn (string $key, mixed $value) => app(SettingsRepository::class)->set($key, $value, 'test');
    $this->declare = fn ($user, array $input = []) => app(InvestmentDeclarations::class)->save($user, $this->employee, '2026-27', $input + ['regime' => 'old', 'items' => []], true);
});

it('opens declarations only on the configured days of the month and can keep payroll to verifying', function () {
    ($this->set)('payroll.tax.declaration_day_from', 1);
    ($this->set)('payroll.tax.declaration_day_to', 10);
    expect(app(TaxSettings::class)->declarationOpen())->toBeFalse()
        ->and(fn () => ($this->declare)($this->employee->user))->toThrow(RuntimeException::class, 'Declarations can be changed');
    $this->travelTo('2026-10-05 09:00:00');
    $declaration = ($this->declare)($this->employee->user);

    ($this->set)('payroll.tax.payroll_may_edit', false);
    expect(app(InvestmentDeclarations::class)->canEdit($this->hr, $this->employee, $declaration))->toBeFalse()
        ->and(app(InvestmentDeclarations::class)->canEdit($this->employee->user, $this->employee, $declaration))->toBeTrue();
});

it('shows Form 12BB to employees as configured, and estimates the tax per regime', function () {
    $declaration = ($this->declare)($this->hr, ['items' => ['80C.ppf' => ['amount' => 150000]]]);
    $declaration->update(['status' => 'draft']);
    expect(Form12bbController::visibleFor($declaration, $this->employee->user))->toBeTrue();
    ($this->set)('payroll.tax.form12bb_visibility', 'after_submit');
    expect(Form12bbController::visibleFor($declaration, $this->employee->user))->toBeFalse()
        ->and(Form12bbController::visibleFor($declaration, $this->hr))->toBeTrue();

    $estimate = app(TaxEstimator::class)->compare($this->employee, '2026-27', ['80C' => 150000]);
    expect($estimate)->toHaveKeys(['new', 'old'])->and($estimate['old']['taxable'])->toBeLessThan($estimate['new']['taxable'] + 150000 + 1)
        ->and($estimate['new']['tax'])->toBeGreaterThanOrEqual(0.0);
});

it('lets employees see their own issued certificates when switched on, and can refuse bills under the new regime', function () {
    $this->actingAs($this->employee->user);
    expect(MyTaxCertificateResource::canAccess())->toBeFalse();
    ($this->set)('payroll.tax.form16_self_service', true);
    expect(MyTaxCertificateResource::canAccess())->toBeTrue();
    $this->actingAs($this->hr);

    $fuel = SalaryComponent::create(['name' => 'Fuel', 'code' => 'FUEL', 'type' => 'reimbursement', 'classification' => 'reimbursement', 'calculation_method' => 'fixed', 'taxable' => false, 'include_in_ctc' => true,
        'include_in_gross' => false, 'is_recurring' => true, 'is_proratable' => false, 'pay_on_claim' => true, 'claim_attachment_required' => false, 'sort_order' => 50, 'status' => 'active']);
    ($this->set)('payroll.claims.allow_beyond_balance', true);
    EmployeeTaxDeclaration::create(['employee_id' => $this->employee->id, 'financial_year' => '2026-27', 'regime' => 'new', 'declarations' => [], 'status' => 'submitted']);
    ($this->set)('payroll.claims.new_regime_bills', 'block');
    expect(fn () => app(ReimbursementClaims::class)->claim($this->employee, $fuel, 500, '2026-09-20', 'Fuel'))->toThrow(RuntimeException::class, 'new tax regime');
});
