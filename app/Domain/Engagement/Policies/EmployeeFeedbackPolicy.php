<?php

namespace App\Domain\Engagement\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use Illuminate\Database\Eloquent\Model;

/** Phase 13: the feedback inbox (engagement.feedback, identified items in scope). Feedback is never edited or deleted. */
class EmployeeFeedbackPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('engagement.feedback');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission('engagement.feedback') && ($model->getAttribute('employee_id') === null || app(AccessScopes::class)->allowsEmployeeId($user, (int) $model->getAttribute('employee_id')));
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('engagement.participate');
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
