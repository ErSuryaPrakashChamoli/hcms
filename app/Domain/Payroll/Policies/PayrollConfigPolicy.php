<?php

namespace App\Domain\Payroll\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Salary components, structures, adjustments, statutory profiles. */
class PayrollConfigPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('payroll.view') || $user->hasPermission('payroll.manage');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('payroll.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('payroll.manage');
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->hasPermission('payroll.manage') && ! ($model->getAttribute('is_statutory') ?? false);
    }
}
