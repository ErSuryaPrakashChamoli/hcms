<?php

namespace App\Domain\Compliance\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use Illuminate\Database\Eloquent\Model;

/**
 * Statutory returns and their rows. Visible only with compliance.returns.view (never through a
 * reporting line) and within the company scope. Changes go through StatutoryReturns, which checks
 * the step permission and separation of duties itself; nothing is edited or deleted directly.
 */
class StatutoryReturnPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('compliance.returns.view');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user) && app(AccessScopes::class)->allows($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('compliance.returns.generate');
    }

    public function update(User $user, Model $model): bool
    {
        return false;
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
