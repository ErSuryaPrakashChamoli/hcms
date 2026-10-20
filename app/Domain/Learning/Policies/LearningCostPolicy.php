<?php

namespace App\Domain\Learning\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Learning costs are financial: learning.costs only, never deleted. */
class LearningCostPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('learning.costs');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission('learning.costs');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('learning.costs');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('learning.costs');
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
