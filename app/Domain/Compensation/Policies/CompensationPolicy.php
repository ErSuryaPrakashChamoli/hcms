<?php

namespace App\Domain\Compensation\Policies;

use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Models\EmployeeSalaryAssignment;
use App\Domain\Compensation\Services\CompensationAccess;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 11: employee compensation rows and compensation changes. Rows are never created, edited or
 * deleted from a screen or API: they are written only by executing an approved change.
 */
class CompensationPolicy
{
    public function viewAny(User $user): bool
    {
        foreach (['compensation.view', 'compensation.team', 'compensation.self', 'compensation.propose', 'compensation.review', 'compensation.approve', 'compensation.execute'] as $permission) {
            if ($user->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    public function view(User $user, Model $model): bool
    {
        $access = app(CompensationAccess::class);
        if ($model instanceof CompensationChange) {
            return $access->mayViewChange($user, $model);
        }
        $employee = $model instanceof EmployeeSalaryAssignment ? Employee::query()->withoutGlobalScope(AccessScope::class)->find($model->employee_id) : null;

        return $employee !== null && $access->mayViewCompensation($user, $employee);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('compensation.propose');
    }

    public function update(User $user, Model $model): bool
    {
        return $model instanceof CompensationChange && $model->status === 'draft' && (int) $model->proposed_by === (int) $user->id;
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
