<?php

namespace App\Domain\Analytics\Policies;

use App\Domain\Analytics\Models\Dashboard;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

class DashboardPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('analytics.view') || $user->hasPermission('analytics.manage');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user) && $model instanceof Dashboard && $model->isForUser($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('analytics.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('analytics.manage');
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->hasPermission('analytics.manage') && ! $model->getAttribute('is_default');
    }
}
