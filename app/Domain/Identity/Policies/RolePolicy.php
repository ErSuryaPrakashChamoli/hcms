<?php

namespace App\Domain\Identity\Policies;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

class RolePolicy extends PermissionPolicy
{
    protected string $resource = 'role';

    public function delete(User $user, Model $model): bool
    {
        return $model instanceof Role && ! $model->is_system && parent::delete($user, $model);
    }
}
