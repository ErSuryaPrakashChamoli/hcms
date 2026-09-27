<?php

namespace App\Domain\Identity\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

class UserPolicy extends PermissionPolicy
{
    protected string $resource = 'user';

    public function view(User $user, Model $model): bool
    {
        return $this->sameTenant($user, $model) && parent::view($user, $model);
    }

    public function update(User $user, Model $model): bool
    {
        return $this->sameTenant($user, $model) && parent::update($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->sameTenant($user, $model) && $user->isNot($model) && parent::delete($user, $model);
    }

    public function assignRoles(User $user, Model $model): bool
    {
        return $this->sameTenant($user, $model) && $user->hasPermission('user.assign_roles');
    }

    private function sameTenant(User $user, Model $model): bool
    {
        return $model instanceof User
            && $model->tenant_id !== null
            && $model->tenant_id === $user->tenant_id;
    }
}
