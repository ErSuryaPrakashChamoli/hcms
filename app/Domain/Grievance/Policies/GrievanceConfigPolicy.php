<?php

namespace App\Domain\Grievance\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

class GrievanceConfigPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('grievance.manage');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('grievance.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('grievance.manage');
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->hasPermission('grievance.manage');
    }
}
