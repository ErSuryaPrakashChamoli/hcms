<?php

namespace App\Domain\Payroll\Policies;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Identity\Services\OwnRecords;
use App\Domain\Payroll\Services\VariablePayPlans;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne setup parity BP-9: variable pay plans are kept by payroll (payroll.manage, in scope); the employee's manager sees
 * them to enter payouts; the employee sees their own when compensation.self_service.show_variable_plan is on.
 */
class VariablePayPlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('payroll.manage') || $user->hasPermission('payroll.request');
    }

    public function view(User $user, Model $model): bool
    {
        if ($user->hasPermission('payroll.manage') && app(AccessScopes::class)->allows($user, $model)) {
            return true;
        }
        if (app(OwnRecords::class)->isOwn($user, $model->employee_id)) {
            return (bool) app(SettingsRepository::class)->get('compensation.self_service.show_variable_plan', false);
        }
        $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->find($model->employee_id);

        return $employee !== null && app(VariablePayPlans::class)->mayEnter($user, $employee);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('payroll.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('payroll.manage') && app(AccessScopes::class)->allows($user, $model) && ! app(OwnRecords::class)->isOwn($user, $model->employee_id);
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
