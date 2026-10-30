<?php

namespace App\Domain\Recognition\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne setup parity HP-9c: badges are set up by HR (recognition.manage). Givers do not need this screen: the badges
 * they may give are offered in "Give a badge" (Recognitions::badgesFor). Standard badges and badges already given
 * are switched off, never deleted.
 */
class BadgePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('recognition.manage');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('recognition.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('recognition.manage');
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->hasPermission('recognition.manage') && ! $model->getAttribute('is_system');
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
