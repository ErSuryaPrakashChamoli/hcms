<?php

namespace App\Domain\Organisation\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\PermissionPolicy;
use Illuminate\Database\Eloquent\Model;

/** Legal entities are closed (effective_to / inactive), never deleted: statutory history hangs off them. */
class LegalEntityPolicy extends PermissionPolicy
{
    protected string $resource = 'legal_entity';

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
