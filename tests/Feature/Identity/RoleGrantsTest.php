<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Services\Blueprints;
use App\Domain\Enterprise\Models\SsoConnection;
use App\Domain\Enterprise\Services\Sso;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\PermissionRegistry;
use App\Domain\Identity\Services\RoleGrants;
use App\Domain\Platform\Models\Tenant;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\SsoConnections\Pages\CreateSsoConnection;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
 | SaaS.10 (findings E1–E3): the delegation ceiling. A narrower first administrator (Account Owner) is real only if
 | nobody below Tenant Super Admin can give that role, write permissions beyond their own into a role, or make it the
 | default role of an SSO connection.
 */

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
});

function userWithSystemRole(Tenant $tenant, string $slug): User
{
    return app(TenantContext::class)->runAs($tenant, function () use ($tenant, $slug) {
        $user = User::factory()->forTenant($tenant)->create();
        $user->roles()->attach(Role::query()->where('slug', $slug)->firstOrFail());

        return $user->fresh();
    });
}

function roleId(string $slug): int
{
    return (int) Role::query()->where('slug', $slug)->value('id');
}

it('gives the Account Owner explicit administration keys, never * and never payroll, statutory or role editing', function () {
    $owner = userWithSystemRole($this->tenant, 'account-owner');
    $keys = $owner->permissionKeys()->all();

    expect($keys)->toContain('user.assign_roles', 'company.update', 'organisation.update', 'settings.update', 'data.export', 'audit.view')
        ->and($keys)->not->toContain('payroll.view')
        ->and($keys)->not->toContain('employee.sensitive.view')
        ->and($keys)->not->toContain('compliance.view')
        ->and($keys)->not->toContain('role.update')
        ->and($keys)->not->toContain('blueprint.import')
        ->and($keys)->not->toContain('tenant.create')
        ->and(count($keys))->toBeLessThan(count(app(PermissionRegistry::class)->catalogue()) / 4)
        ->and($owner->isPlatformAdmin())->toBeFalse()
        ->and(config('peopleos.roles.account-owner.permissions'))->not->toContain('*');
});

it('refuses an Account Owner giving anyone, themselves included, the Tenant Super Admin role (E1)', function () {
    $owner = userWithSystemRole($this->tenant, 'account-owner');
    $this->actingAs($owner);

    Livewire::test(EditUser::class, ['record' => $owner->id])
        ->fillForm(['roles' => [roleId('account-owner'), roleId('tenant-super-admin')], 'audit_reason' => 'Make me super admin'])
        ->call('save')
        ->assertHasFormErrors(['roles']);

    expect($owner->fresh()->roleSlugs())->toBe(['account-owner'])
        ->and(AuditEvent::query()->where('action', 'ROLE_CHANGED')->count())->toBe(0);
});

it('lets an Account Owner delegate a fixed specialist role explicitly, and audits who gave which role', function () {
    $owner = userWithSystemRole($this->tenant, 'account-owner');
    $colleague = userWithSystemRole($this->tenant, 'employee');
    $this->actingAs($owner);

    Livewire::test(EditUser::class, ['record' => $colleague->id])
        ->fillForm(['roles' => [roleId('employee'), roleId('payroll-admin')], 'audit_reason' => 'Runs our payroll'])
        ->call('save')
        ->assertHasNoFormErrors();

    $event = AuditEvent::query()->where('action', 'ROLE_CHANGED')->sole();
    expect($colleague->fresh()->roleSlugs())->toContain('payroll-admin')
        ->and($event->metadata['granted'])->toBe(['payroll-admin'])
        ->and($event->metadata['removed'])->toBe([])
        ->and($event->actor_id)->toBe($owner->id)
        ->and($event->reason)->toBe('Runs our payroll');
});

it('refuses a Tenant HR Admin giving the Tenant Super Admin role, as before SaaS.10 it could (E1)', function () {
    $hr = userWithSystemRole($this->tenant, 'tenant-hr-admin');
    $target = userWithSystemRole($this->tenant, 'employee');
    $this->actingAs($hr);

    Livewire::test(EditUser::class, ['record' => $target->id])
        ->fillForm(['roles' => [roleId('employee'), roleId('tenant-super-admin')], 'audit_reason' => 'Escalate'])
        ->call('save')
        ->assertHasFormErrors(['roles']);

    expect($target->fresh()->roleSlugs())->toBe(['employee']);
});

it('lets a Tenant Super Admin, and a platform operator, give the Tenant Super Admin role', function () {
    $super = userWithSystemRole($this->tenant, 'tenant-super-admin');
    $target = userWithSystemRole($this->tenant, 'employee');
    $grants = app(RoleGrants::class);
    $role = Role::query()->where('slug', 'tenant-super-admin')->firstOrFail();

    expect($grants->canAssign($super, $role))->toBeTrue()
        ->and($grants->canAssign(platformAdmin(), $role))->toBeTrue()
        ->and($grants->canAssign(userWithSystemRole($this->tenant, 'account-owner'), $role))->toBeFalse();

    $this->actingAs($super);
    Livewire::test(EditUser::class, ['record' => $target->id])
        ->fillForm(['roles' => [roleId('employee'), roleId('tenant-super-admin')], 'audit_reason' => 'Second owner of the tenant'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($target->fresh()->roleSlugs())->toContain('tenant-super-admin');
});

it('keeps an existing Super Admin assignment when someone below edits that user (only new grants are judged)', function () {
    $super = userWithSystemRole($this->tenant, 'tenant-super-admin');
    $hr = userWithSystemRole($this->tenant, 'tenant-hr-admin');
    $this->actingAs($hr);

    Livewire::test(EditUser::class, ['record' => $super->id])
        ->fillForm(['name' => 'Renamed Owner', 'audit_reason' => 'Name corrected'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($super->fresh()->name)->toBe('Renamed Owner')->and($super->fresh()->roleSlugs())->toBe(['tenant-super-admin']);
});

it('refuses a custom role that grants more than the giver holds, and allows one within it', function () {
    $owner = userWithSystemRole($this->tenant, 'account-owner');
    $target = userWithSystemRole($this->tenant, 'employee');
    $wide = Role::factory()->create(['slug' => 'payroll-plus']);
    $wide->permissions()->sync(app(PermissionRegistry::class)->idsMatching(['payroll.*']));
    $narrow = Role::factory()->create(['slug' => 'org-viewer']);
    $narrow->permissions()->sync(app(PermissionRegistry::class)->idsMatching(['organisation.view', 'company.view']));
    $this->actingAs($owner);

    Livewire::test(EditUser::class, ['record' => $target->id])
        ->fillForm(['roles' => [roleId('employee'), $wide->id], 'audit_reason' => 'Custom payroll role'])
        ->call('save')
        ->assertHasFormErrors(['roles']);
    Livewire::test(EditUser::class, ['record' => $target->id])
        ->fillForm(['roles' => [roleId('employee'), $narrow->id], 'audit_reason' => 'Can see the structure'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->fresh()->roleSlugs())->toContain('org-viewer')->not->toContain('payroll-plus');
});

it('refuses adding permissions to a role beyond the editor\'s own, in the role editor', function () {
    $editor = tenantUser($this->tenant, ['role.*', 'user.view', 'company.view']);
    $role = Role::factory()->create(['slug' => 'helpdesk']);
    $role->permissions()->sync(app(PermissionRegistry::class)->idsMatching(['company.view']));
    $this->actingAs($editor);
    $payroll = (int) Permission::query()->where('key', 'payroll.finalize')->value('id');
    $company = (int) Permission::query()->where('key', 'company.view')->value('id');

    Livewire::test(EditRole::class, ['record' => $role->id])
        ->fillForm(['permissions' => [$company, $payroll], 'audit_reason' => 'Let helpdesk finalise payroll'])
        ->call('save')
        ->assertHasFormErrors(['permissions']);

    expect($role->fresh()->permissions()->pluck('key')->all())->toBe(['company.view']);
});

it('refuses a blueprint import that writes permissions beyond the importer\'s own into a role (E2)', function () {
    $importer = tenantUser($this->tenant, ['blueprint.*', 'user.view']);
    $this->actingAs($importer);
    $blueprint = ['format' => Blueprints::FORMAT, 'roles' => [['slug' => 'employee', 'name' => 'Employee', 'permissions' => ['*']]]];

    expect(fn () => app(Blueprints::class)->import($blueprint, 'Make every employee all-powerful'))
        ->toThrow(RuntimeException::class, 'permissions you do not hold yourself');
    expect(Role::query()->where('slug', 'employee')->first()->permissions()->where('key', 'payroll.finalize')->exists())->toBeFalse();

    // A Tenant Super Admin may define any role (the import's original purpose: copying a configured tenant).
    $this->actingAs(userWithSystemRole($this->tenant, 'tenant-super-admin'));
    app(Blueprints::class)->import(['format' => Blueprints::FORMAT, 'roles' => [['slug' => 'reviewers', 'name' => 'Reviewers', 'permissions' => ['payroll.view']]]], 'Copied from Acme');
    expect(Role::query()->where('slug', 'reviewers')->first()->permissions()->pluck('key')->all())->toBe(['payroll.view']);
});

it('never makes Tenant Super Admin the role SSO gives new users, in the form or at sign-in (E3)', function () {
    $hr = userWithSystemRole($this->tenant, 'tenant-hr-admin');
    $this->actingAs($hr);

    Livewire::test(CreateSsoConnection::class)
        ->fillForm(['name' => 'Entra', 'provider' => 'entra', 'client_id' => 'cid', 'client_secret' => 'secret', 'scopes' => 'openid profile email',
            'authorization_url' => 'https://login.example.test/authorize', 'token_url' => 'https://login.example.test/token', 'userinfo_url' => 'https://login.example.test/userinfo',
            'default_role_id' => roleId('tenant-super-admin'), 'status' => 'active'])
        ->call('create')
        ->assertHasFormErrors(['default_role_id']);

    // A connection configured before the guard still never hands out the role.
    $connection = SsoConnection::create(['name' => 'Old', 'provider' => 'entra', 'slug' => 'old-entra', 'client_id' => 'cid', 'client_secret' => 'secret',
        'authorization_url' => 'https://login.example.test/authorize', 'token_url' => 'https://login.example.test/token', 'userinfo_url' => 'https://login.example.test/userinfo',
        'allowed_domains' => ['example.test'], 'auto_provision' => true, 'default_role_id' => roleId('tenant-super-admin')]);
    actAsTenant(null);
    $user = app(Sso::class)->resolveUser($connection->fresh(), ['sub' => 'new-1', 'email' => 'new.person@example.test', 'name' => 'New Person', 'email_verified' => true]);

    expect($user->roleSlugs())->toBe([]);
});

it('keeps the console unrestricted (provisioning, seeders, permission sync) and a signed-out web request restricted', function () {
    $role = Role::query()->where('slug', 'tenant-super-admin')->firstOrFail();

    expect(app(RoleGrants::class)->canAssign(null, $role))->toBeTrue(); // tests run in the console

    $app = app();
    (fn () => $this->isRunningInConsole = false)->call($app);
    try {
        expect(app(RoleGrants::class)->canAssign(null, $role))->toBeFalse();
    } finally {
        (fn () => $this->isRunningInConsole = true)->call($app);
    }
});
