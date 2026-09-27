<?php

namespace App\Domain\Exit\Policies;

use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/** HR sees all; the employee sees their own; managers and clearance owners see the cases they act on. */
class ExitCasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('exit.view') || $user->hasPermission('exit.manage') || $user->hasPermission('exit.clear') || $user->hasPermission('exit.resign');
    }

    public function view(User $user, Model $model): bool
    {
        if ($user->hasPermission('exit.view') || $user->hasPermission('exit.manage') || $user->hasPermission('exit.settle')) {
            return true;
        }
        if (self::isOwn($user, $model)) {
            return true;
        }
        if ($model instanceof ExitCase) {
            $me = Employee::query()->where('user_id', $user->id)->value('id');
            if ($me && $model->manager_id === $me) {
                return true;
            }

            return $model->clearances()->get()->contains(fn ($c) => $c->isOwnedBy($user));
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('exit.manage') || $user->hasPermission('exit.resign');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('exit.manage');
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public static function isOwn(User $user, Model $model): bool
    {
        return Employee::query()->where('user_id', $user->id)->where('id', $model->getAttribute('employee_id'))->exists();
    }
}
