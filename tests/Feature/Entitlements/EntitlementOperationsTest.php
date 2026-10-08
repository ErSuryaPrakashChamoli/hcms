<?php

use App\Domain\Enterprise\Services\Retention;
use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Models\EntitlementShadowObservation;
use App\Domain\Entitlements\Services\EntitlementConfiguration;
use App\Domain\Entitlements\Services\Entitlements;
use App\Domain\Entitlements\Services\ShadowRecorder;
use App\Filament\Pages\PlatformEntitlementsPage;

/* SaaS.3 §19, §24: operators can read shadow results and explain decisions; observations are purged by retention. */

beforeEach(function () {
    $this->travelTo('2027-01-04 09:00:00');
    $this->tenant = provisionTenant('Alpha');
    $this->operator = platformAdmin();
    actAsTenant($this->tenant);
    app(Entitlements::class)->observe(Capability::Payroll, 'payroll.run.calculate');
    app(ShadowRecorder::class)->flush();
});

it('explains a tenant\'s decisions and summarises shadow observations from the console', function () {
    app(EntitlementConfiguration::class)->configure($this->tenant, '2027-01-04', 'Contract', $this->operator);
    app(EntitlementConfiguration::class)->set($this->tenant, Capability::Payroll, true, '2027-01-04', null, 'Payroll', $this->operator);

    $this->artisan('peopleos:entitlements:explain', ['tenant' => $this->tenant->slug, 'capability' => 'payroll'])
        ->expectsOutputToContain('configured from 2027-01-04')->assertSuccessful();
    $this->artisan('peopleos:entitlements:explain', ['tenant' => $this->tenant->slug, 'capability' => 'no.such'])->assertFailed();
    $this->artisan('peopleos:entitlements:shadow-report', ['--by-surface' => true])->expectsOutputToContain('TENANT_UNCONFIGURED')->assertSuccessful();
});

it('shows operators the cross-tenant shadow summary, and nobody else', function () {
    actAsTenant(null);
    $this->actingAs($this->operator)->get(PlatformEntitlementsPage::getUrl())->assertOk()->assertSee('payroll.run.calculate')->assertSee('All tenants');
    $this->actingAs(tenantUser($this->tenant, ['*']))->get(PlatformEntitlementsPage::getUrl())->assertForbidden();
});

it('purges aggregated observations after the retention window, tenant by tenant', function () {
    expect(EntitlementShadowObservation::query()->count())->toBe(1);
    $this->travelTo('2027-04-05 09:00:00'); // 91 days later
    $purged = app(Retention::class)->purge();

    expect($purged['entitlement_shadow_observations'])->toBe(1)->and(EntitlementShadowObservation::query()->count())->toBe(0);
});
