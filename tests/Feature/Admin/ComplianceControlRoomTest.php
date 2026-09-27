<?php

use App\Domain\Compliance\Services\ComplianceReadiness;
use App\Domain\Compliance\Services\Returns\EpfReturns;
use App\Domain\Compliance\Services\Returns\StatutoryReturns;
use App\Filament\Pages\ComplianceControlRoom;
use App\Filament\Pages\PayrollControlRoom;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Compliance/ComplianceTestHelpers.php';

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo('2026-10-05 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    ['company' => $this->company, 'establishment' => $this->establishment] = complianceCompany();
    statutoryEmployee(600000, $this->establishment, '100200300400');
    finalizedPayroll($this->company, 2026, 9, $this->admin, tenantUser($this->tenant, ['payroll.*', 'employee.*']));
    ['generator' => $this->generator, 'approver' => $this->approver] = complianceUsers($this->tenant);
    $returns = app(StatutoryReturns::class);
    $this->return = $returns->export($returns->approve($returns->validate(app(EpfReturns::class)->generate($this->establishment, 2026, 9, $this->generator), $this->generator), $this->approver), $this->generator);
});

it('keeps the production gate closed while rules and formats are unverified, and says why', function () {
    $gate = app(ComplianceReadiness::class)->forReturn($this->return);
    $failed = collect($gate['checks'])->reject(fn ($c) => $c['passed'])->pluck('check')->all();

    expect($gate['ready'])->toBeFalse()
        ->and($failed)->toBe(['rules_verified', 'export_format_verified'])
        ->and(collect($gate['checks'])->firstWhere('check', 'approved_with_separation_of_duties')['passed'])->toBeTrue()
        ->and(collect($gate['checks'])->firstWhere('check', 'audit_chain_intact')['passed'])->toBeTrue();
});

it('renders the control rooms with the exported-not-filed distinction', function () {
    actAsTenant(null);
    $this->get(ComplianceControlRoom::getUrl())->assertOk()
        ->assertSee('Exported, not yet filed')->assertSee('Verified-rule enforcement is OFF')
        ->assertSee($this->establishment->name)->assertSee('2026-09');
    $this->get(PayrollControlRoom::getUrl())->assertOk()->assertSee('Compliance control room')->assertSee('not VERIFIED');
});
