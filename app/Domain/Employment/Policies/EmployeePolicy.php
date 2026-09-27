<?php

namespace App\Domain\Employment\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\PermissionPolicy;
use Illuminate\Database\Eloquent\Model;

class EmployeePolicy extends PermissionPolicy
{
    protected string $resource = 'employee';

    public function viewSensitive(User $user, Model $model): bool
    {
        return $user->hasPermission('employee.sensitive.view');
    }

    public function updateSensitive(User $user, Model $model): bool
    {
        return $user->hasPermission('employee.sensitive.update');
    }

    public function assignPosition(User $user, Model $model): bool
    {
        return $user->hasPermission('employee.position');
    }

    public function transition(User $user, Model $model): bool
    {
        return $user->hasPermission('employee.lifecycle');
    }
}
