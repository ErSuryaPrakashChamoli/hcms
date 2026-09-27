<?php

use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Services\PermissionRegistry;
use App\Domain\Organisation\Models\Company;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
});

it('grants permissions through roles', function () {
    $user = tenantUser($this->tenant, ['company.view']);

    expect($user->hasPermission('company.view'))->toBeTrue()
        ->and($user->hasPermission('company.update'))->toBeFalse()
        ->and($user->can('company.view'))->toBeTrue()
        ->and($user->can('company.update'))->toBeFalse();
});

it('expands wildcard patterns when provisioning roles', function () {
    $user = tenantUser($this->tenant, ['company.*']);

    expect($user->permissionKeys()->all())->toEqualCanonicalizing(['company.view', 'company.create', 'company.update', 'company.delete']);
});

it('resolves policy abilities from permission keys', function () {
    $company = Company::factory()->create();
    $viewer = tenantUser($this->tenant, ['company.view']);
    $editor = tenantUser($this->tenant, ['company.view', 'company.update']);

    expect(Gate::forUser($viewer)->allows('update', $company))->toBeFalse()
        ->and(Gate::forUser($viewer)->allows('view', $company))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('update', $company))->toBeTrue();
});

it('gives platform admins everything', function () {
    $admin = platformAdmin();
    $company = Company::factory()->create();

    expect($admin->hasPermission('anything.at_all'))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $company))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('viewAny', Tenant::class))->toBeTrue();
});

it('never lets tenant users touch tenants, whatever their roles say', function () {
    $superAdmin = tenantUser($this->tenant, ['*']);

    expect($superAdmin->hasPermission('tenant.view'))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('viewAny', Tenant::class))->toBeFalse()
        ->and(Gate::forUser($superAdmin)->allows('update', $this->tenant))->toBeFalse();
});

it('scopes company-specific role grants', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = tenantUser($this->tenant);

    $role = Role::factory()->create();
    $role->permissions()->sync(app(PermissionRegistry::class)->idsMatching(['company.update']));
    $user->roles()->attach($role, ['company_id' => $companyA->id]);

    expect($user->hasPermission('company.update', $companyA->id))->toBeTrue()
        ->and($user->hasPermission('company.update', $companyB->id))->toBeFalse()
        ->and($user->hasPermission('company.update'))->toBeTrue();
});

it('provisions the blueprint system roles for every tenant', function () {
    $slugs = Role::query()->where('is_system', true)->pluck('slug')->all();

    expect($slugs)->toEqualCanonicalizing(array_keys(config('peopleos.roles')));

    $superAdmin = Role::query()->where('slug', 'tenant-super-admin')->first();
    expect($superAdmin->permissions()->count())->toBe(count(app(PermissionRegistry::class)->catalogue()));
});

it('forbids deleting system roles but allows deleting custom ones', function () {
    $admin = tenantUser($this->tenant, ['role.*']);
    $system = Role::query()->where('slug', 'employee')->first();
    $custom = Role::factory()->create();

    expect(Gate::forUser($admin)->allows('delete', $system))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('delete', $custom))->toBeTrue();
});

it('keeps the catalogue in sync and prunes unknown keys', function () {
    Permission::create(['key' => 'legacy.zombie', 'module' => 'legacy']);

    $result = app(PermissionRegistry::class)->sync();

    expect($result['pruned'])->toBe(1)
        ->and(Permission::query()->where('key', 'legacy.zombie')->exists())->toBeFalse();
});
