<?php

use App\Domain\Identity\Models\Role;
use App\Domain\Organisation\Models\Company;
use App\Filament\Resources\AuditEvents\AuditEventResource;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\TenantFeatures\TenantFeatureResource;
use App\Filament\Resources\Tenants\TenantResource;
use App\Filament\Resources\TenantSettings\TenantSettingResource;
use App\Filament\Resources\Users\UserResource;
use App\Support\Tenancy\TenantContext;

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->company = Company::factory()->create();
    $this->admin = tenantUser($this->tenant, ['*']);
    actAsTenant(null);
    $this->actingAs($this->admin);
});

it('renders every tenant-facing admin page', function () {
    $this->get('/admin')->assertOk();
    $this->get(CompanyResource::getUrl('index'))->assertOk();
    $this->get(CompanyResource::getUrl('create'))->assertOk();
    $this->get(CompanyResource::getUrl('edit', ['record' => $this->company]))->assertOk();
    $this->get(CompanyResource::getUrl('view', ['record' => $this->company]))->assertOk();
    $this->get(UserResource::getUrl('index'))->assertOk()->assertSee($this->admin->name);
    $this->get(UserResource::getUrl('create'))->assertOk();
    $this->get(UserResource::getUrl('edit', ['record' => $this->admin]))->assertOk();
    $this->get(RoleResource::getUrl('index'))->assertOk()->assertSee('Auditor');
    $this->get(RoleResource::getUrl('create'))->assertOk();
    $this->get(RoleResource::getUrl('edit', ['record' => Role::query()->where('slug', 'employee')->firstOrFail()]))->assertOk();
    $this->get(TenantSettingResource::getUrl('index'))->assertOk()->assertSee('branding.primary_colour');
    $this->get(TenantFeatureResource::getUrl('index'))->assertOk()->assertSee('organisation.designer');
    $this->get(AuditEventResource::getUrl('index'))->assertOk()->assertSee('Create');
});

it('renders the audit event detail page with field changes', function () {
    $event = app(TenantContext::class)->runAs($this->tenant, fn () => $this->company->auditEvents()->first());

    $this->get(AuditEventResource::getUrl('view', ['record' => $event]))
        ->assertOk()
        ->assertSee($this->company->code)
        ->assertSee('Hash verified');
});

it('renders the platform tenant pages for a platform admin', function () {
    $this->actingAs(platformAdmin());

    $this->get('/admin')->assertOk();
    $this->get(TenantResource::getUrl('index'))->assertOk()->assertSee($this->tenant->name);
    $this->get(TenantResource::getUrl('create'))->assertOk()->assertSee('First administrator');
    $this->get(TenantResource::getUrl('edit', ['record' => $this->tenant]))->assertOk();
});
