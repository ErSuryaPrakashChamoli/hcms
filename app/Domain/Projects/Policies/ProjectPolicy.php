<?php

namespace App\Domain\Projects\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Data import DI-1: customers, projects and project members are seen with project.view and kept with project.manage. */
class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('project.view') || $user->hasPermission('project.manage');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('project.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('project.manage');
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->hasPermission('project.manage');
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasPermission('project.manage');
    }
}
