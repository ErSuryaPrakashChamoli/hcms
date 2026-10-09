<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * SaaS.10: the delegation ceiling. Before it, anyone holding `user.assign_roles` could give anyone (themselves
 * included) the Tenant Super Admin role, a blueprint import could rewrite any role's permissions, and an SSO
 * connection could hand every new user the Super Admin role (findings E1–E3). That made a narrower first
 * administrator (`account-owner`) cosmetic.
 *
 * The rule:
 * - Unrestricted: a platform operator, a holder of the Tenant Super Admin role, and the console with no signed-in user
 *   (provisioning, seeders, `peopleos:sync-permissions`).
 * - Everyone else may grant any role whose permissions they all hold themselves (that raises nobody above the giver),
 *   and a system role other than Tenant Super Admin (fixed, Markedge-reviewed templates such as Payroll Admin: an
 *   explicit delegation). A custom role beyond their own permissions, and Tenant Super Admin (unless they already hold
 *   every permission it grants), they may not.
 * - Writing permissions into a role (role editor, blueprint import) is limited to permissions the writer holds.
 *
 * Removing roles or permissions is not limited here (it never raises anyone's rights).
 */
final class RoleGrants
{
    public const SUPER_ADMIN = 'tenant-super-admin';

    /** Whether $actor may give $role to someone (directly, or as an SSO connection's default role). */
    public function canAssign(?User $actor, Role $role): bool
    {
        if ($this->unrestricted($actor)) {
            return true;
        }
        if ($actor === null) {
            return false;
        }
        // Giving what one already holds raises nobody above the giver.
        if ($this->beyond($actor, $role->permissions()->pluck('key')->all()) === []) {
            return true;
        }

        return $role->is_system && $role->slug !== self::SUPER_ADMIN;
    }

    /**
     * Refuses the first role of $roles that $actor may not give.
     *
     * @param  iterable<Role>  $roles
     */
    public function assertCanAssign(?User $actor, iterable $roles): void
    {
        foreach ($roles as $role) {
            if (! $this->canAssign($actor, $role)) {
                throw new RuntimeException($this->refusal($role));
            }
        }
    }

    /**
     * The roles among $roleIds that $actor may not give (for form validation). Roles the target already holds are
     * passed in $alreadyHeld and never count: keeping an existing assignment raises nobody's rights.
     *
     * @param  array<int|string>  $roleIds
     * @param  array<int|string>  $alreadyHeld
     * @return Collection<int, Role>
     */
    public function refused(?User $actor, array $roleIds, array $alreadyHeld = []): Collection
    {
        $new = array_values(array_diff(array_map('intval', $roleIds), array_map('intval', $alreadyHeld)));
        if ($new === [] || $this->unrestricted($actor)) {
            return collect();
        }

        return Role::query()->whereIn('id', $new)->get()->reject(fn (Role $role) => $this->canAssign($actor, $role))->values();
    }

    /**
     * Refuses writing permissions into a role beyond what $actor holds (role editor, blueprint import).
     *
     * @param  array<int|string>  $permissionKeys
     */
    public function assertCanDefine(?User $actor, array $permissionKeys, string $what): void
    {
        if ($this->unrestricted($actor)) {
            return;
        }
        $beyond = $actor === null ? array_values($permissionKeys) : $this->beyond($actor, $permissionKeys);
        if ($beyond !== []) {
            throw new RuntimeException("You cannot {$what} with permissions you do not hold yourself: ".implode(', ', array_slice($beyond, 0, 5)).(count($beyond) > 5 ? ' …' : '').'.');
        }
    }

    /**
     * Permission ids among $permissionIds (keys looked up) that $actor does not hold.
     *
     * @param  array<int|string>  $permissionIds
     * @return list<string> the keys
     */
    public function keysBeyond(?User $actor, array $permissionIds): array
    {
        if ($this->unrestricted($actor) || $permissionIds === []) {
            return [];
        }
        $keys = Permission::query()->whereIn('id', array_map('intval', $permissionIds))->pluck('key')->all();

        return $actor === null ? array_values($keys) : $this->beyond($actor, $keys);
    }

    /**
     * Records a change of a user's roles (ROLE_CHANGED on the tenant chain) when one happened: who gave or removed which
     * role. Role assignments were not audited before SaaS.10.
     *
     * @param  list<int>  $before  role ids before the change
     */
    public function recordChange(User $user, array $before, ?User $actor, ?string $reason = null): void
    {
        $after = $user->roles()->pluck('roles.id')->map(fn ($id) => (int) $id)->all();
        [$added, $removed] = [array_values(array_diff($after, $before)), array_values(array_diff($before, $after))];
        if ($added === [] && $removed === []) {
            return;
        }
        $slugs = Role::query()->whereIn('id', [...$added, ...$removed])->pluck('slug', 'id');
        $user->flushPermissionCache();
        app(AuditRecorder::class)->record(AuditAction::RoleChanged, 'identity', $user, [], $reason, actor: $actor, metadata: [
            'granted' => array_values(array_map(fn ($id) => $slugs[$id] ?? (string) $id, $added)),
            'removed' => array_values(array_map(fn ($id) => $slugs[$id] ?? (string) $id, $removed)),
        ]);
    }

    /** @return list<string> the permission keys a role grants now (for recordPermissionChange) */
    public function permissionKeysOf(Role $role): array
    {
        return $role->permissions()->pluck('key')->sort()->values()->all();
    }

    /**
     * SaaS.11: records a change of a role's permissions (ROLE_CHANGED on the tenant chain, with the role and the keys
     * granted and removed) when one happened. Editing a role's permissions (role editor, blueprint import) was not audited:
     * a role could be given payroll.finalize without a trace.
     *
     * @param  list<string>  $before  the role's permission keys before the change
     */
    public function recordPermissionChange(Role $role, array $before, ?User $actor, ?string $reason = null): void
    {
        $after = $this->permissionKeysOf($role);
        [$granted, $removed] = [array_values(array_diff($after, $before)), array_values(array_diff($before, $after))];
        if ($granted === [] && $removed === []) {
            return;
        }
        app(AuditRecorder::class)->record(AuditAction::RoleChanged, 'identity', $role, [], $reason, actor: $actor, metadata: [
            'role' => $role->slug, 'system_role' => (bool) $role->is_system, 'permissions_granted' => $granted, 'permissions_removed' => $removed,
        ]);
    }

    public function refusal(Role $role): string
    {
        return $role->slug === self::SUPER_ADMIN
            ? "Only a Tenant Super Admin can assign the role \"{$role->name}\"."
            : "You cannot assign the role \"{$role->name}\": it grants permissions you do not hold yourself.";
    }

    public function unrestricted(?User $actor): bool
    {
        if ($actor === null) {
            return app()->runningInConsole();
        }
        if ($actor->isPlatformAdmin()) {
            return true;
        }

        return in_array(self::SUPER_ADMIN, $actor->roleSlugs(), true);
    }

    /**
     * @param  array<int|string>  $keys
     * @return list<string>
     */
    private function beyond(User $actor, array $keys): array
    {
        $held = $actor->permissionKeys()->all();
        // Platform keys (tenant.*) are never effective for a tenant user (User::permissionKeys), so they are not compared.
        $keys = array_filter(array_unique(array_map('strval', $keys)), fn (string $key) => ! str_starts_with($key, 'tenant.'));

        return array_values(array_diff($keys, $held));
    }
}
