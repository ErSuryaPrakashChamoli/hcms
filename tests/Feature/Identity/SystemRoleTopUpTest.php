<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\PermissionRegistry;

/*
| SaaS.12 deployment safety: `peopleos:sync-permissions` runs on every deploy. It may give a tenant's system roles the
| permissions a release introduces (audited), but it never gives back a permission the tenant removed, and it never
| touches custom roles, role assignments or passwords.
*/

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
});

function systemRole(string $slug): Role
{
    return Role::query()->where('slug', $slug)->firstOrFail();
}

function deploySync(object $test, $tenant): void
{
    $test->artisan('peopleos:sync-permissions')->assertSuccessful();
    actAsTenant($tenant);
}

it('keeps a permission the tenant removed from a system role removed across deploys', function () {
    $role = systemRole('hr-manager');
    $role->permissions()->detach(Permission::query()->where('key', 'compensation.view')->value('id'));

    deploySync($this, $this->tenant);
    deploySync($this, $this->tenant);

    expect(systemRole('hr-manager')->permissions()->pluck('key'))->not->toContain('compensation.view')
        ->toContain('compensation.propose');
});

it('keeps it removed for a role recorded before template tracking (the first deploy sets the baseline)', function () {
    $role = systemRole('hr-manager');
    $role->permissions()->detach(Permission::query()->where('key', 'compensation.view')->value('id'));
    $role->forceFill(['template_keys' => null])->saveQuietly();

    deploySync($this, $this->tenant);

    $role = systemRole('hr-manager');
    expect($role->permissions()->pluck('key'))->not->toContain('compensation.view')
        ->and($role->template_keys)->toContain('compensation.view');
});

it('gives system roles the permissions a release introduces, and audits the change', function () {
    config(['peopleos.permissions.leave' => [...config('peopleos.permissions.leave'), 'leave.s12_probe' => 'Probe permission']]);

    deploySync($this, $this->tenant);

    expect(systemRole('hr-manager')->permissions()->pluck('key'))->toContain('leave.s12_probe');
    $event = AuditEvent::query()->where('action', 'ROLE_CHANGED')->where('metadata->role', 'hr-manager')->sole();
    expect($event->metadata['permissions_granted'])->toBe(['leave.s12_probe'])
        ->and($event->metadata['permissions_removed'])->toBe([])
        ->and($event->actor_id)->toBeNull();

    deploySync($this, $this->tenant); // nothing new: no second event
    expect(AuditEvent::query()->where('action', 'ROLE_CHANGED')->where('metadata->role', 'hr-manager')->count())->toBe(1);
});

it('never touches custom roles, role assignments or passwords', function () {
    $custom = Role::factory()->create(['slug' => 'night-shift-leads']);
    $custom->permissions()->sync(Permission::query()->whereIn('key', ['attendance.view'])->pluck('id'));
    $user = tenantUser($this->tenant, ['attendance.view']);
    $user->roles()->attach($custom->id);
    $hash = $user->fresh()->password;

    deploySync($this, $this->tenant);

    expect($custom->fresh()->permissions()->pluck('key')->all())->toBe(['attendance.view'])
        ->and($user->fresh()->roles()->pluck('slug'))->toContain('night-shift-leads')
        ->and(User::query()->whereKey($user->id)->value('password'))->toBe($hash);
});

it('gives a new tenant\'s system roles their full template and records it', function () {
    $role = systemRole('hr-manager');
    $template = app(PermissionRegistry::class)->keysMatching(config('peopleos.roles.hr-manager.permissions'));

    expect($role->permissions()->pluck('key')->sort()->values()->all())->toBe(collect($template)->sort()->values()->all())
        ->and(collect($role->template_keys)->sort()->values()->all())->toBe(collect($template)->sort()->values()->all());
});
