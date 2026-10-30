<?php

namespace App\Domain\Loans\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Identity\Services\OwnRecords;
use Illuminate\Database\Eloquent\Model;

/** Loans: payroll managers in scope decide and see all; an employee sees and raises their own (payroll.request). */
class LoanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('payroll.manage') || $user->hasPermission('payroll.request');
    }

    public function view(User $user, Model $model): bool
    {
        return ($user->hasPermission('payroll.manage') && app(AccessScopes::class)->allows($user, $model)) || $this->isOwn($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('payroll.request') || $user->hasPermission('payroll.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('payroll.manage') && app(AccessScopes::class)->allows($user, $model);
    }

    public function approve(User $user, Model $model): bool
    {
        return $this->update($user, $model) && ! $this->isOwn($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    private function isOwn(User $user, Model $model): bool
    {
        return app(OwnRecords::class)->isOwn($user, $model->employee_id);
    }
}
