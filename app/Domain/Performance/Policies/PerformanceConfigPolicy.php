<?php

namespace App\Domain\Performance\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Cycles, rating scales, competencies, KRA library, career paths. */
class PerformanceConfigPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('performance.view') || $user->hasPermission('performance.manage');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('performance.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('performance.manage');
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->hasPermission('performance.manage') && ($model->getAttribute('status') === 'draft' || ! $model->getAttribute('status') instanceof \BackedEnum && $model->getAttribute('status') !== 'active');
    }
}
