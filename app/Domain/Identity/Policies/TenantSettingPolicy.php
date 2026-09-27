<?php

namespace App\Domain\Identity\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

class TenantSettingPolicy extends PermissionPolicy
{
    protected string $resource = 'settings';

    public function create(User $user): bool
    {
        return $user->hasPermission('settings.update');
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
