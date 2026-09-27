<?php

namespace App\Domain\Ai\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Users see their own conversations; AI admins and auditors see the log. */
class AiInteractionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('ai.admin') || $user->hasPermission('audit.view');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user) || $model->getAttribute('user_id') === $user->id;
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
