<?php

namespace App\Domain\Leave\Policies;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Leave requests, balances, encashments: own vs team vs everyone. */
class LeaveRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('leave.view') || $user->hasPermission('leave.apply');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission('leave.view') || $this->isOwn($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('leave.apply') || $user->hasPermission('leave.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('leave.manage');
    }

    public function approve(User $user, Model $model): bool
    {
        return $user->hasPermission('leave.approve') && ! $this->isOwn($user, $model);
    }

    public function cancel(User $user, Model $model): bool
    {
        return $user->hasPermission('leave.manage') || ($user->hasPermission('leave.apply') && $this->isOwn($user, $model));
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    private function isOwn(User $user, Model $model): bool
    {
        return Employee::query()->where('user_id', $user->id)->where('id', $model->employee_id)->exists();
    }
}
