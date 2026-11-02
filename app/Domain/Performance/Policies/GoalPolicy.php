<?php

namespace App\Domain\Performance\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Support\GoalVisibility;
use Illuminate\Database\Eloquent\Model;

/**
 * Goals follow the employee-owned rules (EmployeeOwnedPolicy). HROne parity GO-4 adds:
 * - a private goal is opened or changed only by someone GoalVisibility lets see it (owner decision PER-5 / D-GO5);
 * - `checkIn`: whoever may update the goal, and the owner person of an organisation goal (they report its progress);
 * - `overrideAchievement`: while `performance.goals.achievement_override` is on, performance HR or the performance manager
 *   of the goal's person, never on their own goal (owner decision PER-6 / D-GO7).
 */
class GoalPolicy extends EmployeeOwnedPolicy
{
    public function view(User $user, Model $model): bool
    {
        return GoalVisibility::canSee($user, $model) && parent::view($user, $model);
    }

    public function update(User $user, Model $model): bool
    {
        return GoalVisibility::canSee($user, $model) && parent::update($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return GoalVisibility::canSee($user, $model) && parent::delete($user, $model);
    }

    public function checkIn(User $user, Goal $goal): bool
    {
        if (! GoalVisibility::canSee($user, $goal)) {
            return false;
        }
        if (parent::update($user, $goal)) {
            return true;
        }

        return $goal->owner_employee_id !== null && $goal->employee_id === null
            && (int) self::employeeOf($user)?->id === (int) $goal->owner_employee_id;
    }

    public function overrideAchievement(User $user, Goal $goal): bool
    {
        return GoalVisibility::overrideEnabled() && GoalVisibility::mayOverride($user, $goal);
    }
}
