<?php

namespace App\Domain\Ai\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Users see their own conversations; only AI governance (ai.admin) reads everyone's. Phase 14: audit.view
 * alone no longer opens the log: questions can carry personal context the auditor is not entitled to.
 */
class AiInteractionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('ai.admin');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user) || (int) $model->getAttribute('user_id') === (int) $user->id;
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
