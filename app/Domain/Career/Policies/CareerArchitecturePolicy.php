<?php

namespace App\Domain\Career\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Performance\Models\CareerPath;
use App\Domain\Performance\Policies\PerformanceConfigPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Career tracks, career paths and their versions, role requirements. Phase 7 performance
 * administrators keep their access to career paths; Phase 9 adds career.view / career.manage.
 * Published versions are immutable, so nothing here is deletable except what Phase 7 allowed.
 */
class CareerArchitecturePolicy extends PerformanceConfigPolicy
{
    public function viewAny(User $user): bool
    {
        return parent::viewAny($user) || $user->hasPermission('career.view') || $user->hasPermission('career.manage');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return parent::create($user) || $user->hasPermission('career.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return parent::update($user, $model) || $user->hasPermission('career.manage');
    }

    public function delete(User $user, Model $model): bool
    {
        return $model instanceof CareerPath && parent::delete($user, $model);
    }
}
