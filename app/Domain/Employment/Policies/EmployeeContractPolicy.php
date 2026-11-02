<?php

namespace App\Domain\Employment\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Contracts of contract staff: seen with employee.view, kept with employee.update (EmployeeContracts checks the scope). Never deleted. */
class EmployeeContractPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('employee.view');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('employee.update');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('employee.update');
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
