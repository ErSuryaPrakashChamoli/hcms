<?php

namespace App\Domain\Development\Policies;

use App\Domain\Development\Services\DevelopmentPlans;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Performance\Services\PerformanceRelationships;
use Illuminate\Database\Eloquent\Model;

/** Development plans: development.view sees all; the employee their own; managers (development.team) the employees they manage. */
class DevelopmentPlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('development.view') || $user->hasPermission('development.manage') || $user->hasPermission('development.team') || $user->hasPermission('development.own');
    }

    public function view(User $user, Model $model): bool
    {
        if (($user->hasPermission('development.view') || $user->hasPermission('development.manage')) && app(AccessScopes::class)->allows($user, $model)) {
            return true;
        }
        $relationships = app(PerformanceRelationships::class);
        $me = $relationships->forUser($user);
        $employeeId = $model->getAttribute('employee_id');

        return ($me !== null && (int) $me->id === (int) $employeeId) || ($user->hasPermission('development.team') && $relationships->manages($me, $employeeId));
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('development.manage') || $user->hasPermission('development.team') || $user->hasPermission('development.own');
    }

    public function update(User $user, Model $model): bool
    {
        $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->find($model->getAttribute('employee_id'));

        return $employee !== null && app(DevelopmentPlans::class)->mayManage($employee, $user);
    }

    public function delete(User $user, Model $model): bool
    {
        return $model->getAttribute('status') === 'draft' && $this->update($user, $model);
    }
}
