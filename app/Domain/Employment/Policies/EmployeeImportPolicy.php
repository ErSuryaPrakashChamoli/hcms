<?php

namespace App\Domain\Employment\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Imports are high risk: one permission for the whole pipeline, tenant-bound by the model scope. */
class EmployeeImportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('employee.import');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission('employee.import');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('employee.import');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('employee.import') && ! $model->getAttribute('imported_at');
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
