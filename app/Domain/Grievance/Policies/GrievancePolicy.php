<?php

namespace App\Domain\Grievance\Policies;

use App\Domain\Grievance\Models\Grievance;
use App\Domain\Grievance\Services\Grievances;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Case access is decided by Grievances::canAccess, never by a blanket permission. */
class GrievancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('grievance.view') || $user->hasPermission('grievance.manage') || $user->hasPermission('grievance.raise');
    }

    public function view(User $user, Model $model): bool
    {
        return $model instanceof Grievance && app(Grievances::class)->canAccess($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('grievance.raise') || $user->hasPermission('grievance.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $this->view($user, $model) && ($user->hasPermission('grievance.manage') || $model->getAttribute('assignee_id') === $user->id);
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
