<?php

namespace App\Domain\Engagement\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne setup parity HP-9f: mood bot reasons are engagement configuration (engagement.view to list, engagement.manage to
 * add, rename, order and switch them). A reason is made inactive rather than deleted, so past check-ins keep it.
 */
class MoodReasonPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('engagement.view') || $user->hasPermission('engagement.manage');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('engagement.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('engagement.manage');
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
