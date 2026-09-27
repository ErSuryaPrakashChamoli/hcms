<?php

namespace App\Domain\Payroll\Policies;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use Illuminate\Database\Eloquent\Model;

/** Payroll staff see every payslip; employees see their own. */
class PayslipPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('payroll.view') || $user->hasPermission('payroll.payslip');
    }

    public function view(User $user, Model $model): bool
    {
        // Salary is sensitive: managers get nothing from the reporting line alone; payroll.view plus organisation scope, or own payslip.
        return ($user->hasPermission('payroll.view') && app(AccessScopes::class)->allows($user, $model)) || Employee::query()->where('user_id', $user->id)->where('id', $model->employee_id)->exists();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Model $model): bool
    {
        return false;
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
