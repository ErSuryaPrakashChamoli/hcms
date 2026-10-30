<?php

namespace App\Domain\Identity\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity CA-9: data-access groups are read with `user.view` and changed with `user.assign_roles` (the right that
 * already edits a user's organisation scope), so every role keeps the access it had.
 */
class AccessScopeGroupPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('user.view');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission('user.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('user.assign_roles');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('user.assign_roles');
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->hasPermission('user.assign_roles');
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
