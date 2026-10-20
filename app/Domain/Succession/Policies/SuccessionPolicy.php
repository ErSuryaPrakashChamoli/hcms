<?php

namespace App\Domain\Succession\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Talent\Services\TalentAccess;
use Illuminate\Database\Eloquent\Model;

/**
 * Critical positions, succession plans, successors and readiness. Position-level records need
 * succession.view / succession.manage. Person-level records (successors, readiness) follow
 * TalentAccess: succession.view within scope, succession.team for employees one manages (configured
 * relationship types only), and the employee only with succession.own_candidacy. Nothing is deleted.
 */
class SuccessionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('succession.view') || $user->hasPermission('succession.manage') || $user->hasPermission('succession.team');
    }

    public function view(User $user, Model $model): bool
    {
        $employeeId = $model->getAttribute('employee_id');
        if ($employeeId === null) {
            return $user->hasPermission('succession.view') || $user->hasPermission('succession.manage');
        }

        return app(TalentAccess::class)->mayViewSuccession($user, $employeeId);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('succession.manage') || $user->hasPermission('succession.assess');
    }

    public function update(User $user, Model $model): bool
    {
        $employeeId = $model->getAttribute('employee_id');

        return $employeeId === null ? $user->hasPermission('succession.manage') : app(TalentAccess::class)->mayManageSuccession($user, $employeeId);
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
