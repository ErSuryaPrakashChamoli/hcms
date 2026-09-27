<?php

namespace App\Domain\Bgv\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

class BgvPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('bgv.view');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission('bgv.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('bgv.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('bgv.manage');
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
