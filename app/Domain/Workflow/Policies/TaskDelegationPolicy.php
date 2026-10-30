<?php

namespace App\Domain\Workflow\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Workflow\Models\InboxArchive;
use App\Domain\Workflow\Models\TaskDelegation;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne setup parity P4: out-of-office delegations and archived inbox items are personal. A person sees and manages their
 * own out-of-office periods (task.delegate) and sees those handed to them; task.delegate_any sees and manages everyone's.
 * Archive rows belong to their person only. Nothing is deleted.
 */
class TaskDelegationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('task.delegate') || $user->hasPermission('task.delegate_any');
    }

    public function view(User $user, Model $model): bool
    {
        return match (true) {
            $model instanceof TaskDelegation => $user->hasPermission('task.delegate_any') || in_array((int) $user->id, [(int) $model->delegator_id, (int) $model->delegate_id], true),
            $model instanceof InboxArchive => (int) $model->user_id === (int) $user->id,
            default => false,
        };
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Model $model): bool
    {
        return match (true) {
            $model instanceof TaskDelegation => $user->hasPermission('task.delegate_any') || ((int) $model->delegator_id === (int) $user->id && $user->hasPermission('task.delegate')),
            $model instanceof InboxArchive => (int) $model->user_id === (int) $user->id,
            default => false,
        };
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
