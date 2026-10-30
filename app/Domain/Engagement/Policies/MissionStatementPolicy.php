<?php

namespace App\Domain\Engagement\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne setup parity HP-9b: mission statements are engagement configuration. Employees read the one meant for them on
 * Home; changing them needs engagement.manage. A statement is made inactive rather than deleted (its history stays).
 */
class MissionStatementPolicy
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
