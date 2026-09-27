<?php

namespace App\Domain\Employment\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\PermissionPolicy;
use Illuminate\Database\Eloquent\Model;

/** Bank accounts and statutory details (blueprint §80). */
class SensitiveEmployeeDataPolicy extends PermissionPolicy
{
    protected string $resource = 'employee.sensitive';

    public function create(User $user): bool
    {
        return $user->hasPermission('employee.sensitive.update');
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->hasPermission('employee.sensitive.update');
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasPermission('employee.sensitive.update');
    }
}
