<?php

namespace App\Domain\Expense\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne setup parity EX-F: travel requests and their legs follow the expense records (own with `expense.claim`; in scope
 * with `expense.view` / `expense.approve` / `expense.pay`; never deciding one's own), and the travel desk
 * (`expense.travel_desk`) also sees trips in its scope and records company bookings — never on its own trips.
 */
class TravelRequestPolicy extends ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return parent::viewAny($user) || $user->hasPermission('expense.travel_desk');
    }

    public function view(User $user, Model $model): bool
    {
        return parent::view($user, $model) || ($user->hasPermission('expense.travel_desk') && $this->withinScope($user, $model));
    }

    /** Records a company booking on a leg (the travel desk, in scope, never its own trip). */
    public function book(User $user, Model $model): bool
    {
        return $user->hasPermission('expense.travel_desk') && $this->withinScope($user, $model) && ! $this->isOwn($user, $model);
    }
}
