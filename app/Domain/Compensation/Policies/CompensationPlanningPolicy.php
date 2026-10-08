<?php

namespace App\Domain\Compensation\Policies;

use App\Domain\Compensation\Models\CompensationBudget;
use App\Domain\Compensation\Models\CompensationCycle;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 11: compensation budgets (compensation.budget) and cycles (compensation.cycles). Reviewers,
 * approvers and executors see them to act; every state change goes through the domain services.
 */
class CompensationPlanningPolicy
{
    public function viewAny(User $user, ?string $class = null): bool
    {
        foreach (['compensation.view', 'compensation.budget', 'compensation.cycles', 'compensation.review', 'compensation.approve', 'compensation.execute'] as $permission) {
            if ($user->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('compensation.budget') || $user->hasPermission('compensation.cycles');
    }

    public function update(User $user, Model $model): bool
    {
        return match (true) {
            $model instanceof CompensationBudget => $user->hasPermission('compensation.budget') && $model->status === 'draft',
            $model instanceof CompensationCycle => $user->hasPermission('compensation.cycles') && $model->status === 'draft',
            default => false,
        };
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
