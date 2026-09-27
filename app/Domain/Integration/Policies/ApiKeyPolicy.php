<?php

namespace App\Domain\Integration\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\PermissionPolicy;
use Illuminate\Database\Eloquent\Model;

class ApiKeyPolicy extends PermissionPolicy
{
    protected string $resource = 'api_key';

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('api_key.manage');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission('api_key.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('api_key.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('api_key.manage');
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->hasPermission('api_key.manage');
    }
}
