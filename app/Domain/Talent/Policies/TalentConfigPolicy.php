<?php

namespace App\Domain\Talent\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Talent pools, assessment models and versions: talent.view to read, talent.manage to configure. Review sessions: TalentReviewPolicy. */
class TalentConfigPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('talent.view') || $user->hasPermission('talent.manage');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('talent.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('talent.manage');
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
