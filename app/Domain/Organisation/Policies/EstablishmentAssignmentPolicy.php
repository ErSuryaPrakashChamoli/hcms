<?php

namespace App\Domain\Organisation\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use Illuminate\Database\Eloquent\Model;

/** Assignment history: viewed with establishment.view, recorded with establishment.update; never edited or deleted. */
class EstablishmentAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('establishment.view');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user) && app(AccessScopes::class)->allows($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('establishment.update');
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
