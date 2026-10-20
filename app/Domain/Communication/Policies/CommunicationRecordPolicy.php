<?php

namespace App\Domain\Communication\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Phase 13: recipients and preferences are changed only through their services (delivery, the employee's own preference). */
class CommunicationRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('communication.manage') || $user->hasPermission('communication.approve');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Model $model): bool
    {
        return false;
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
