<?php

namespace App\Domain\Integration\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Data import DI-1: JV codes are seen with integration.view and kept (set, changed, removed) with integration.manage. */
class JvCodePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('integration.view') || $user->hasPermission('integration.manage');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('integration.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('integration.manage');
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->hasPermission('integration.manage');
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasPermission('integration.manage');
    }
}
