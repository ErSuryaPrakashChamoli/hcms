<?php

namespace App\Domain\Identity\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use Illuminate\Database\Eloquent\Model;

/**
 * Maps the standard policy abilities onto `{resource}.{action}` permission keys, and (Phase 0.2)
 * refuses record-level abilities on records outside the user's organisational access scope.
 * Platform admins short-circuit in Gate::before, so policies only see tenant users.
 */
abstract class PermissionPolicy
{
    protected string $resource;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission("{$this->resource}.view");
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission("{$this->resource}.view") && $this->withinScope($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission("{$this->resource}.create");
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission("{$this->resource}.update") && $this->withinScope($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->hasPermission("{$this->resource}.delete") && $this->withinScope($user, $model);
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasPermission("{$this->resource}.delete");
    }

    public function restore(User $user, Model $model): bool
    {
        return $user->hasPermission("{$this->resource}.update") && $this->withinScope($user, $model);
    }

    public function forceDelete(User $user, Model $model): bool
    {
        return false;
    }

    /** Organisational access scope (ABAC): the record must be reachable by the user. */
    protected function withinScope(User $user, Model $model): bool
    {
        return app(AccessScopes::class)->allows($user, $model);
    }
}
