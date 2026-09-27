<?php

use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Services\ComplianceRules;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Support\Tenancy\TenantContext;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/PayrollTestHelpers.php';

/* Phase 0.2 statutory safety: illustrative rules are marked as such and cannot finalize production payroll. */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->preparer = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->approver = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->actingAs($this->preparer);
    $this->company = payrollCompany();
    $this->runs = app(PayrollRuns::class);
});

it('marks every synced pack rule as illustrative until verified', function () {
    expect(ComplianceRule::query()->count())->toBeGreaterThan(0)
        ->and(ComplianceRule::query()->where('verification_status', 'verified')->count())->toBe(0)
        ->and(app(ComplianceRules::class)->unverified('IN')->isNotEmpty())->toBeTrue();

    $this->artisan('peopleos:compliance:sync')->expectsOutputToContain('ILLUSTRATIVE')->assertSuccessful();
});

it('refuses to finalize payroll on illustrative rules when enforcement is on, and allows it once rules are verified', function () {
    salariedEmployee(600000);
    $run = $this->runs->open($this->company, 2026, 9, $this->preparer);
    $run = $this->runs->calculate($run, $this->preparer);
    $run = $this->runs->validate($run);
    $run = $this->runs->approve($run, $this->approver, 'ok');

    config(['peopleos.compliance.enforce_verified_rules' => true]);
    expect(fn () => $this->runs->finalize($run, $this->approver))->toThrow(RuntimeException::class, 'illustrative');
    expect($run->refresh()->status)->toBe('approved');

    app(TenantContext::class)->bypass(fn () => ComplianceRule::query()->update(['verification_status' => 'verified', 'verified_at' => now()]));
    expect($this->runs->finalize($run, $this->approver)->status)->toBe('finalized');
});

it('does not enforce verification outside production by default', function () {
    expect(config('peopleos.compliance.enforce_verified_rules'))->toBeFalsy();
});
