<?php

namespace App\Domain\Learning\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Courses, paths, assignments, sessions. Learners may browse published courses. */
class LearningConfigPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('learning.view') || $user->hasPermission('learning.manage') || $user->hasPermission('learning.assign') || $user->hasPermission('learning.learn');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('learning.manage') || $user->hasPermission('learning.assign');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('learning.manage') || $user->hasPermission('learning.assign');
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->hasPermission('learning.manage');
    }
}
