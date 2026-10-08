<?php

use App\Domain\Organisation\Models\Company;
use App\Domain\Platform\Services\PlatformTenantAccess;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Companies\Pages\CreateCompany;
use App\Filament\Resources\Tenants\TenantResource;
use App\Http\Middleware\ResolveTenant;
use Livewire\Livewire;

beforeEach(function () {
    $this->tenantA = provisionTenant('Tenant A');
    $this->tenantB = provisionTenant('Tenant B');

    actAsTenant($this->tenantA);
    $this->companyA = Company::factory()->create(['name' => 'Alpha Co']);
    actAsTenant(null);
});

it('lets a tenant admin list and view their own companies', function () {
    $this->actingAs(tenantUser($this->tenantA, ['company.*']));

    $this->get(CompanyResource::getUrl('index'))->assertOk()->assertSee('Alpha Co');
    $this->get(CompanyResource::getUrl('view', ['record' => $this->companyA]))->assertOk();
});

it('hides another tenant\'s company even with a direct URL', function () {
    $this->actingAs(tenantUser($this->tenantB, ['company.*']));

    $this->get(CompanyResource::getUrl('index'))->assertOk()->assertDontSee('Alpha Co');
    $this->get(CompanyResource::getUrl('view', ['record' => $this->companyA]))->assertNotFound();
});

it('denies users without the permission', function () {
    $this->actingAs(tenantUser($this->tenantA, ['user.view']));

    $this->get(CompanyResource::getUrl('index'))->assertForbidden();
});

it('keeps suspended users and users of suspended tenants out of the panel', function () {
    // SaaS.2: the session is signed out, not merely refused, so reactivation does not revive it.
    $login = filament()->getPanel('admin')->getLoginUrl();
    $this->actingAs(tenantUser($this->tenantA, ['company.*'], ['status' => 'suspended']));
    $this->get(CompanyResource::getUrl('index'))->assertRedirect($login);
    $this->assertGuest();

    $this->tenantB->update(['status' => 'suspended']);
    $this->actingAs(tenantUser($this->tenantB, ['company.*']));
    $this->get(CompanyResource::getUrl('index'))->assertRedirect($login);
    $this->assertGuest();
});

it('hides tenant administration from tenant users but shows it to platform admins', function () {
    $this->actingAs(tenantUser($this->tenantA, ['*']));
    $this->get(TenantResource::getUrl('index'))->assertForbidden();

    $this->actingAs(platformAdmin());
    $this->get(TenantResource::getUrl('index'))->assertOk()->assertSee('Tenant A')->assertSee('Tenant B');
});

it('lets a platform admin enter a tenant and see its data, and leave it again', function () {
    $admin = platformAdmin();
    $this->actingAs($admin);

    $this->get(CompanyResource::getUrl('index'))->assertOk()->assertDontSee('Alpha Co');

    // SaaS.2: the old bare session key no longer opens a tenant.
    actAsTenant(null);
    $this->withSession([ResolveTenant::SESSION_KEY => $this->tenantA->id])
        ->get(CompanyResource::getUrl('index'))->assertOk()->assertDontSee('Alpha Co');

    app(PlatformTenantAccess::class)->enter($admin, $this->tenantA, 'Customer support ticket review', 'SUP-1', session()->driver());
    $this->get(CompanyResource::getUrl('index'))->assertOk()->assertSee('Alpha Co');

    $this->post(route('admin.exit-tenant'))->assertRedirect();
    expect(session()->has(PlatformTenantAccess::SESSION_KEY))->toBeFalse();
    actAsTenant(null); // a new request starts unbound (the scoped context outlives requests only inside a test)
    $this->get(CompanyResource::getUrl('index'))->assertOk()->assertDontSee('Alpha Co');
});

it('creates a company from the admin form, stamped with the tenant and audited', function () {
    $user = tenantUser($this->tenantA, ['company.*']);
    $this->actingAs($user);
    actAsTenant($this->tenantA);

    Livewire::test(CreateCompany::class)
        ->fillForm([
            'name' => 'Beta Co',
            'code' => 'BETA',
            'status' => 'active',
            'country_code' => 'IN',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $company = Company::query()->where('code', 'BETA')->first();

    expect($company->tenant_id)->toBe($this->tenantA->id)
        ->and($company->auditEvents()->where('action', 'CREATE')->value('actor_id'))->toBe($user->id);
});
