<?php

namespace App\Domain\Identity\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

class TenantFeaturePolicy extends PermissionPolicy
{
    protected string $resource = 'features';

    public function create(User $user): bool
    {
        return false;
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
