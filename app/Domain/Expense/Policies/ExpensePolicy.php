<?php

namespace App\Domain\Expense\Policies;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\PermissionPolicy;
use App\Domain\Identity\Services\OwnRecords;
use Illuminate\Database\Eloquent\Model;

/**
 * Expense receipts, reports and their parts, advances and their ledger (employee-linked, access-scoped):
 *
 * - an employee sees and files their own (`expense.claim`);
 * - `expense.view`, `expense.approve` and `expense.pay` holders see records in their access scope;
 * - `expense.approve` decides in scope (and may file on someone's behalf), `expense.pay` records payments and
 *   dispenses, recovers and writes off advances in scope — never on their own records.
 */
class ExpensePolicy extends PermissionPolicy
{
    protected string $resource = 'expense';

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('expense.claim') || $this->oversees($user);
    }

    public function view(User $user, Model $model): bool
    {
        return ($this->oversees($user) && $this->withinScope($user, $model)) || $this->isOwn($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('expense.claim') || $user->hasPermission('expense.approve');
    }

    /** The employee changes their own draft records; approvers in scope act for them (on behalf). */
    public function update(User $user, Model $model): bool
    {
        return ($user->hasPermission('expense.claim') && $this->isOwn($user, $model)) || $this->onBehalf($user, $model);
    }

    public function approve(User $user, Model $model): bool
    {
        return $user->hasPermission('expense.approve') && $this->withinScope($user, $model) && ! $this->isOwn($user, $model);
    }

    public function pay(User $user, Model $model): bool
    {
        return $user->hasPermission('expense.pay') && $this->withinScope($user, $model) && ! $this->isOwn($user, $model);
    }

    /** Filing for another employee (HR or the manager): `expense.approve` in scope. */
    public function onBehalf(User $user, Model $model): bool
    {
        return $user->hasPermission('expense.approve') && $this->withinScope($user, $model) && ! $this->isOwn($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Model $model): bool
    {
        return false;
    }

    protected function oversees(User $user): bool
    {
        return $user->hasPermission('expense.view') || $user->hasPermission('expense.approve') || $user->hasPermission('expense.pay');
    }

    /** A record of the user's own employee (or, for onBehalf, the user's own employee record). */
    protected function isOwn(User $user, Model $model): bool
    {
        $employeeId = $model instanceof Employee ? $model->getKey() : ($model->getAttributes()['employee_id'] ?? null);

        return $employeeId !== null && app(OwnRecords::class)->isOwn($user, $employeeId);
    }
}
