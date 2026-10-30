<?php

namespace App\Domain\Lifecycle\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use Illuminate\Database\Eloquent\Model;

/** Confirmation reviews: those who move employees through the lifecycle see and decide them, within their scope. */
class ConfirmationReviewPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('employee.lifecycle');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user) && app(AccessScopes::class)->allows($user, $model);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Model $model): bool
    {
        return $this->view($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
