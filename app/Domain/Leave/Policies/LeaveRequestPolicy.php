<?php

namespace App\Domain\Leave\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Identity\Services\OwnRecords;
use Illuminate\Database\Eloquent\Model;

/** Leave requests, balances, encashments: own vs team vs everyone. */
class LeaveRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('leave.view') || $user->hasPermission('leave.apply');
    }

    public function view(User $user, Model $model): bool
    {
        return ($user->hasPermission('leave.view') && $this->inScope($user, $model)) || $this->isOwn($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('leave.apply') || $user->hasPermission('leave.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('leave.manage') && $this->inScope($user, $model);
    }

    public function approve(User $user, Model $model): bool
    {
        return $user->hasPermission('leave.approve') && ! $this->isOwn($user, $model) && $this->inScope($user, $model);
    }

    public function cancel(User $user, Model $model): bool
    {
        return ($user->hasPermission('leave.manage') && $this->inScope($user, $model)) || ($user->hasPermission('leave.apply') && $this->isOwn($user, $model));
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    /** Organisation + relationship scope (ADR-0004): a manager approves direct reports, never unrelated employees. */
    private function inScope(User $user, Model $model): bool
    {
        return app(AccessScopes::class)->allows($user, $model);
    }

    private function isOwn(User $user, Model $model): bool
    {
        // Same query as before; inside an authorisation pass the viewer's own ids are read once (OwnRecords).
        return app(OwnRecords::class)->isOwn($user, $model->employee_id);
    }
}
