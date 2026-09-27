<?php

namespace App\Domain\Compliance\Policies;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

class TaxDeclarationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('payroll.view') || $user->hasPermission('payroll.payslip');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission('payroll.view') || $this->isOwn($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('payroll.manage') || $user->hasPermission('payroll.payslip');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('payroll.manage') || ($this->isOwn($user, $model) && $model->status !== 'verified');
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    private function isOwn(User $user, Model $model): bool
    {
        return Employee::query()->where('user_id', $user->id)->where('id', $model->employee_id)->exists();
    }
}
