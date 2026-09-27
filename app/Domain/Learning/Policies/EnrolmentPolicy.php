<?php

namespace App\Domain\Learning\Policies;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Enrolments and certificates: staff see all, learners their own, managers their direct reports. */
class EnrolmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('learning.view') || $user->hasPermission('learning.assign') || $user->hasPermission('learning.learn');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission('learning.view') || $user->hasPermission('learning.assign') || self::isOwn($user, $model) || self::isReport($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('learning.assign') || $user->hasPermission('learning.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('learning.assign') || $user->hasPermission('learning.manage') || self::isOwn($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public static function isOwn(User $user, Model $model): bool
    {
        return Employee::query()->where('user_id', $user->id)->where('id', $model->getAttribute('employee_id'))->exists();
    }

    public static function isReport(User $user, Model $model): bool
    {
        $me = Employee::query()->where('user_id', $user->id)->first();

        return $me !== null && $me->directReports()->currentlyEffective()->where('employee_id', $model->getAttribute('employee_id'))->exists();
    }
}
