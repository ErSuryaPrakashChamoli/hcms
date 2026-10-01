<?php

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\PermissionRegistry;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Services\LifecycleEngine;
use App\Domain\Platform\Actions\ProvisionTenantAction;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Phase 8: MySQL concurrency tests manage their own disposable database (no RefreshDatabase).
pest()->extend(TestCase::class)->in('MySql');

/*
|--------------------------------------------------------------------------
| Tenancy helpers
|--------------------------------------------------------------------------
*/

/** Provision a fully seeded tenant (system roles, features, settings) with no admin user. */
function provisionTenant(string $name = 'Acme'): Tenant
{
    app(PermissionRegistry::class)->sync();

    return app(ProvisionTenantAction::class)->handle([
        'name' => $name,
        'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
    ]);
}

function actAsTenant(?Tenant $tenant): void
{
    app(TenantContext::class)->set($tenant);
}

/**
 * A tenant user holding one ad-hoc role with the given permission patterns (`company.*`, `*`).
 *
 * @param  list<string>  $permissions
 */
function tenantUser(Tenant $tenant, array $permissions = [], array $attributes = []): User
{
    return app(TenantContext::class)->runAs($tenant, function () use ($tenant, $permissions, $attributes) {
        $user = User::factory()->forTenant($tenant)->create($attributes);

        if ($permissions !== []) {
            $role = Role::factory()->create();
            $role->permissions()->sync(app(PermissionRegistry::class)->idsMatching($permissions));
            $user->roles()->attach($role);
        }

        return $user;
    });
}

function platformAdmin(): User
{
    return User::factory()->platformAdmin()->create();
}

/**
 * Put an employee directly into a lifecycle state for test setup, bypassing the engine's guard
 * (production code must use LifecycleEngine::transition()).
 *
 * @param  array<string, mixed>  $extra
 */
function forceLifecycle(Employee $employee, LifecycleState|string $state, array $extra = []): Employee
{
    LifecycleEngine::unguarded(fn () => $employee->update(['lifecycle_state' => $state] + $extra));

    return $employee;
}
