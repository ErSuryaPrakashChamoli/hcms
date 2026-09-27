<?php

namespace App\Domain\Analytics\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

class ReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('analytics.reports') || $user->hasPermission('analytics.manage') || $user->hasPermission('analytics.view');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission('analytics.manage') || $model->getAttribute('owner_id') === $user->id || ($model->getAttribute('is_shared') && $this->viewAny($user));
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('analytics.reports') || $user->hasPermission('analytics.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('analytics.manage') || ($model->getAttribute('owner_id') === $user->id && $user->hasPermission('analytics.reports'));
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->update($user, $model);
    }
}
