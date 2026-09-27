<?php

namespace App\Domain\Leave\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

class LeaveTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('leave.view') || $user->hasPermission('leave.apply');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('leave.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('leave.manage');
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->hasPermission('leave.manage');
    }
}
