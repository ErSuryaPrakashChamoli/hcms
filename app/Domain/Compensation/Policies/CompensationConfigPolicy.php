<?php

namespace App\Domain\Compensation\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Phase 11: compensation structures (and their components / versions). Definitions, not anyone's pay. */
class CompensationConfigPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('compensation.configure') || $user->hasPermission('compensation.view') || $user->hasPermission('payroll.view');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('compensation.configure');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('compensation.configure');
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->hasPermission('compensation.configure') && ! (method_exists($model, 'assignments') && $model->assignments()->exists());
    }
}
