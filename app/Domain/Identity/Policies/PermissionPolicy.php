<?php

namespace App\Domain\Identity\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Maps the standard policy abilities onto `{resource}.{action}` permission keys.
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
        return $user->hasPermission("{$this->resource}.view");
    }

    public function create(User $user): bool
    {
        return $user->hasPermission("{$this->resource}.create");
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission("{$this->resource}.update");
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->hasPermission("{$this->resource}.delete");
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasPermission("{$this->resource}.delete");
    }

    public function restore(User $user, Model $model): bool
    {
        return $user->hasPermission("{$this->resource}.update");
    }

    public function forceDelete(User $user, Model $model): bool
    {
        return false;
    }
}
