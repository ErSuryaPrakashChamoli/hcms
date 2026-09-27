<?php

namespace App\Domain\Organisation\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\PermissionPolicy;
use Illuminate\Database\Eloquent\Model;

/** Establishments are closed (effective_to / inactive), never deleted: statutory history hangs off them. */
class EstablishmentPolicy extends PermissionPolicy
{
    protected string $resource = 'establishment';

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
