<?php

namespace App\Domain\Feedback\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\PermissionPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne setup parity P13: question banks and feedback templates are seen with feedback.view and set up with
 * feedback.manage. They are made inactive, never deleted (feedback already asked keeps its own copy of the questions).
 */
class FeedbackConfigPolicy extends PermissionPolicy
{
    protected string $resource = 'feedback';

    public function create(User $user): bool
    {
        return $user->hasPermission('feedback.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('feedback.manage');
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
