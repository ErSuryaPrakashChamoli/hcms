<?php

namespace App\Domain\Workforce\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use Illuminate\Database\Eloquent\Model;

/** Workforce budgets are cost data: workforce.costs plus a planning permission, within organisation scope. */
class WorkforceBudgetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('workforce.costs') && ($user->hasPermission('workforce.view') || $user->hasPermission('workforce.plan') || $user->hasPermission('workforce.approve'));
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user) && app(AccessScopes::class)->allows($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('workforce.costs') && $user->hasPermission('workforce.plan');
    }

    public function update(User $user, Model $model): bool
    {
        return $this->create($user) && $this->view($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
