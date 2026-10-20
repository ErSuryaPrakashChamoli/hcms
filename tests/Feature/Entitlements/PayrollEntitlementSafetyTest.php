<?php

use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Models\EntitlementShadowObservation;
use App\Domain\Entitlements\Services\EntitlementConfiguration;
use App\Domain\Entitlements\Services\EntitlementStateStore;
use App\Domain\Entitlements\Services\ShadowRecorder;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Payroll\Services\PayrollRuns;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/*
| SaaS.3 §26: commercial entitlement evaluation never stops payroll: not calculation, not the run, not statutory
| processing, not payslips. A would-be denial and a broken entitlement engine are both observed and ignored.
*/

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->preparer = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->approver = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->actingAs($this->preparer);
    $this->company = payrollCompany();
    $this->employee = salariedEmployee(600000);
    $this->runs = app(PayrollRuns::class);
});

function runPayrollToTheEnd(object $test)
{
    $run = $test->runs->open($test->company, 2026, 9);
    $run = $test->runs->calculate($run);
    $run = $test->runs->approve($test->runs->validate($run), $test->approver);

    return $test->runs->finalize($run);
}

it('runs payroll to finalisation and payslips when the tenant would be denied payroll', function () {
    $config = app(EntitlementConfiguration::class);
    $operator = platformAdmin();
    $config->configure($this->tenant, '2026-09-21', 'Contract without payroll', $operator);
    $config->set($this->tenant, Capability::Payroll, false, '2026-09-21', null, 'Payroll not purchased', $operator);
    actAsTenant($this->tenant);

    $run = runPayrollToTheEnd($this);

    expect($run->status)->toBe('finalized')
        ->and($run->entries()->count())->toBe(1)
        ->and(Payslip::query()->where('employee_id', $this->employee->id)->exists())->toBeTrue();
    app(ShadowRecorder::class)->flush();
    $observed = EntitlementShadowObservation::query()->where('capability', 'payroll')->pluck('outcome', 'surface')->all();
    expect($observed)->toBe(['payroll.run.calculate' => 'DENY', 'payroll.run.finalize' => 'DENY', 'payroll.run.open' => 'DENY']);
});

it('runs payroll to finalisation and payslips when the entitlement engine is broken', function () {
    app()->bind(EntitlementStateStore::class, fn () => throw new RuntimeException('entitlements down'));

    $run = runPayrollToTheEnd($this);

    expect($run->status)->toBe('finalized')->and(Payslip::query()->where('employee_id', $this->employee->id)->exists())->toBeTrue();
});
