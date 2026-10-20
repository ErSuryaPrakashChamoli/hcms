<?php

namespace App\Domain\Skills\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Skill scales and their versions: readable by anyone working with skills, configured with skills.manage. */
class SkillConfigPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('skills.view') || $user->hasPermission('skills.manage') || $user->hasPermission('skills.assess') || $user->hasPermission('skills.self');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('skills.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('skills.manage');
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
