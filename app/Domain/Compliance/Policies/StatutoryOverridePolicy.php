<?php

namespace App\Domain\Compliance\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use Illuminate\Database\Eloquent\Model;

/** Statutory overrides (HROne parity PY-14): payroll sees them; only payroll.override sets or cancels them. */
class StatutoryOverridePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('payroll.view') || $user->hasPermission('payroll.override');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user) && app(AccessScopes::class)->allows($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('payroll.override');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('payroll.override') && app(AccessScopes::class)->allows($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
