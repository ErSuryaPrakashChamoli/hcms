<?php

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\PermissionRegistry;
use App\Domain\Platform\Actions\ProvisionTenantAction;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

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
