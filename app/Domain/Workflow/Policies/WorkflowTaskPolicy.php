<?php

namespace App\Domain\Workflow\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Workflow\Models\WorkflowTask;
use Illuminate\Database\Eloquent\Model;

class WorkflowTaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('task.view') || $user->hasPermission('task.view_all');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission('task.view_all') || ($model instanceof WorkflowTask && $model->isActionableBy($user));
    }

    public function act(User $user, Model $model): bool
    {
        return $model instanceof WorkflowTask && $user->hasPermission('task.act') && $model->isActionableBy($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('task.reassign');
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
