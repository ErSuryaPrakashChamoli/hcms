<?php

namespace App\Domain\Payroll\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

class PayrollRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('payroll.view');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission('payroll.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('payroll.calculate');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('payroll.calculate');
    }

    public function approve(User $user, Model $model): bool
    {
        return $user->hasPermission('payroll.approve');
    }

    public function finalize(User $user, Model $model): bool
    {
        return $user->hasPermission('payroll.finalize');
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
