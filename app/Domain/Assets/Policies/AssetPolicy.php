<?php

namespace App\Domain\Assets\Policies;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Assets, categories, models, repairs: asset staff manage; employees see what is in their custody. */
class AssetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('asset.view') || $user->hasPermission('asset.manage') || $user->hasPermission('asset.assign') || $user->hasPermission('asset.own');
    }

    public function view(User $user, Model $model): bool
    {
        if ($user->hasPermission('asset.view') || $user->hasPermission('asset.manage') || $user->hasPermission('asset.assign')) {
            return true;
        }
        $employeeId = $model->getAttribute('custodian_id') ?? $model->getAttribute('employee_id');

        return $employeeId !== null && Employee::query()->where('user_id', $user->id)->where('id', $employeeId)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('asset.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('asset.manage');
    }

    public function assign(User $user, Model $model): bool
    {
        return $user->hasPermission('asset.assign') || $user->hasPermission('asset.manage');
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->hasPermission('asset.manage') && ($model->getAttribute('status') === 'in_stock' || ! $model->getAttribute('asset_tag'));
    }
}
