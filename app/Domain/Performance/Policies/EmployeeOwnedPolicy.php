<?php

namespace App\Domain\Performance\Policies;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Goals, appraisals, feedback, one-on-ones, PIPs, aspirations: HR sees all (performance.view);
 * employees see their own; managers (performance.team) see their current direct reports.
 */
class EmployeeOwnedPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('performance.view') || $user->hasPermission('performance.goals') || $user->hasPermission('performance.team') || $user->hasPermission('performance.review');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission('performance.view') || self::isOwn($user, $model) || self::isReport($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('performance.manage') || $user->hasPermission('performance.goals') || $user->hasPermission('performance.team') || $user->hasPermission('performance.feedback');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('performance.manage') || self::isOwn($user, $model) || self::isReport($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->hasPermission('performance.manage');
    }

    public static function employeeOf(User $user): ?Employee
    {
        return Employee::query()->where('user_id', $user->id)->first();
    }

    public static function isOwn(User $user, Model $model): bool
    {
        return $model->getAttribute('employee_id') !== null && Employee::query()->where('user_id', $user->id)->where('id', $model->getAttribute('employee_id'))->exists();
    }

    public static function isReport(User $user, Model $model): bool
    {
        if (! $user->hasPermission('performance.team') || $model->getAttribute('employee_id') === null) {
            return false;
        }
        $me = self::employeeOf($user);

        return $me !== null && $me->directReports()->currentlyEffective()->where('employee_id', $model->getAttribute('employee_id'))->exists();
    }
}
