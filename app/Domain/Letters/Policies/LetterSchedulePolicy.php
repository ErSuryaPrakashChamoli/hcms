<?php

namespace App\Domain\Letters\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/** HROne parity WO-13: letter schedules are kept by people who generate and issue letters. Nothing is deleted. */
class LetterSchedulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('letter.issue');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission('letter.issue');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('letter.issue');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('letter.issue') && ! in_array($model->getAttribute('status'), ['completed', 'cancelled'], true);
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
