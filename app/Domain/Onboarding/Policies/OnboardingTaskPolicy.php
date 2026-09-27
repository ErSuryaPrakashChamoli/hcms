<?php

namespace App\Domain\Onboarding\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Onboarding\Models\OnboardingTask;
use Illuminate\Database\Eloquent\Model;

class OnboardingTaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('onboarding.view') || $user->hasPermission('onboarding.act');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission('onboarding.view') || ($model instanceof OnboardingTask && $model->isActionableBy($user));
    }

    public function act(User $user, Model $model): bool
    {
        return $model instanceof OnboardingTask && ($user->hasPermission('onboarding.manage') || ($user->hasPermission('onboarding.act') && $model->isActionableBy($user)));
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('onboarding.manage');
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
