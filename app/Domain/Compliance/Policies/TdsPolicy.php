<?php

namespace App\Domain\Compliance\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use Illuminate\Database\Eloquent\Model;

/** TDS deductor profiles, financial years and investment proofs: view with compliance.returns.view, change with compliance.tds.manage. */
class TdsPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('compliance.returns.view') || $user->hasPermission('compliance.tds.manage');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user) && app(AccessScopes::class)->allows($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('compliance.tds.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('compliance.tds.manage') && app(AccessScopes::class)->allows($user, $model);
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
