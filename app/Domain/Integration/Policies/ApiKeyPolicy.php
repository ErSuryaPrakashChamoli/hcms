<?php

namespace App\Domain\Integration\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\PermissionPolicy;
use Illuminate\Database\Eloquent\Model;

class ApiKeyPolicy extends PermissionPolicy
{
    protected string $resource = 'api_key';

    /** HROne parity CA-11: api_key.view reads keys, their call logs and usage; only api_key.manage changes them. */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('api_key.manage') || $user->hasPermission('api_key.view');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission('api_key.manage') || $user->hasPermission('api_key.view');
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
