<?php

namespace App\Domain\Feedback\Policies;

use App\Domain\Feedback\Services\FeedbackRequests;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\PermissionPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne setup parity P13: feedback is raised by its process, answered through FeedbackRequests::submit by the person it
 * names, and read only as FeedbackRequests::canRead allows (respondent, the process's readers in scope, the next level
 * when copied). Nobody edits or deletes it.
 */
class FeedbackRequestPolicy extends PermissionPolicy
{
    protected string $resource = 'feedback';

    public function viewAny(User $user): bool
    {
        return true; // the screen lists only what FeedbackRequests::visibleTo returns
    }

    public function view(User $user, Model $model): bool
    {
        return app(FeedbackRequests::class)->canRead($user, $model);
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

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
