<?php

namespace App\Domain\Letters\Policies;

use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Policies\ExitCasePolicy;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

class LetterPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('letter.view') || $user->hasPermission('letter.issue') || $user->hasPermission('letter.manage')
            || Employee::query()->where('user_id', $user->id)->exists(); // "My letters"
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission('letter.view') || $user->hasPermission('letter.issue') || $user->hasPermission('letter.manage')
            || ($model->getAttribute('status') === 'issued' && ExitCasePolicy::isOwn($user, $model));
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('letter.issue');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('letter.issue');
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
