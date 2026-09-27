<?php

namespace App\Domain\Onboarding\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

class OnboardingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('onboarding.view');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission('onboarding.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('onboarding.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('onboarding.manage');
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->hasPermission('onboarding.manage');
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasPermission('onboarding.manage');
    }
}
