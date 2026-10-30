<?php

namespace App\Domain\Expense\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\PermissionPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Expense masters (groups, categories and their limits, location groups and places, tax types): `expense.manage`
 * configures them, `expense.view` reads them. They are never deleted: an inactive master stays on earlier records.
 */
class ExpenseConfigPolicy extends PermissionPolicy
{
    protected string $resource = 'expense';

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('expense.view') || $user->hasPermission('expense.manage');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('expense.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('expense.manage');
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Model $model): bool
    {
        return false;
    }
}
