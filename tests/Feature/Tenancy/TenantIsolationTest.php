<?php

use App\Domain\Organisation\Models\Company;
use App\Support\Tenancy\Exceptions\MissingTenantException;
use App\Support\Tenancy\Exceptions\TenantMismatchException;
use App\Support\Tenancy\TenantContext;

beforeEach(function () {
    $this->tenantA = provisionTenant('Tenant A');
    $this->tenantB = provisionTenant('Tenant B');

    actAsTenant($this->tenantA);
    $this->companyA = Company::factory()->create(['name' => 'A Co']);

    actAsTenant($this->tenantB);
    $this->companyB = Company::factory()->create(['name' => 'B Co']);
});

it('only returns the current tenant\'s records', function () {
    actAsTenant($this->tenantA);

    expect(Company::query()->pluck('name')->all())->toBe(['A Co']);
    expect(Company::query()->find($this->companyB->id))->toBeNull();

    actAsTenant($this->tenantB);

    expect(Company::query()->pluck('name')->all())->toBe(['B Co']);
});

it('fails closed when no tenant is bound', function () {
    actAsTenant(null);

    expect(Company::query()->count())->toBe(0);
});

it('stamps new records with the current tenant', function () {
    actAsTenant($this->tenantA);

    $company = Company::factory()->create();

    expect($company->tenant_id)->toBe($this->tenantA->id);
});

it('refuses to create a record for another tenant', function () {
    actAsTenant($this->tenantA);

    Company::factory()->create(['tenant_id' => $this->tenantB->id]);
})->throws(TenantMismatchException::class);

it('refuses to move a record between tenants', function () {
    actAsTenant($this->tenantA);

    app(TenantContext::class)->bypass(fn () => $this->companyA->update(['tenant_id' => $this->tenantB->id]));
})->throws(TenantMismatchException::class);

it('refuses to create a record with no tenant at all', function () {
    actAsTenant(null);

    Company::factory()->create();
})->throws(MissingTenantException::class);

it('lets platform code see across tenants only inside an explicit bypass', function () {
    actAsTenant(null);

    $all = app(TenantContext::class)->bypass(fn () => Company::query()->orderBy('name')->pluck('name')->all());

    expect($all)->toBe(['A Co', 'B Co']);
    expect(Company::query()->count())->toBe(0);
});

it('restores the previous tenant after runAs', function () {
    actAsTenant($this->tenantA);

    $seen = app(TenantContext::class)->runAs($this->tenantB, fn () => Company::query()->value('name'));

    expect($seen)->toBe('B Co');
    expect(app(TenantContext::class)->id())->toBe($this->tenantA->id);
});
