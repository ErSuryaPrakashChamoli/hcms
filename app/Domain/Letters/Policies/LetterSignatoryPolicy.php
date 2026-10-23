<?php

namespace App\Domain\Letters\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Signing authorities: managed with the templates; whoever issues letters may see them to pick one. */
class LetterSignatoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('letter.manage') || $user->hasPermission('letter.issue');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('letter.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('letter.manage');
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->hasPermission('letter.manage');
    }
}
