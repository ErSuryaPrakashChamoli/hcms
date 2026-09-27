<?php

namespace App\Domain\Alumni\Policies;

use App\Domain\Alumni\Models\AlumniProfile;
use App\Domain\Alumni\Models\AlumniRequest;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Alumni staff see all; an alumnus sees their own profile and requests. */
class AlumniPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('alumni.view') || $user->hasPermission('alumni.manage') || $user->hasPermission('alumni.portal');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission('alumni.view') || $user->hasPermission('alumni.manage') || self::isOwn($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('alumni.manage') || $user->hasPermission('alumni.portal');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('alumni.manage');
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public static function isOwn(User $user, Model $model): bool
    {
        $employeeId = $model instanceof AlumniRequest ? $model->profile()->value('employee_id') : ($model instanceof AlumniProfile ? $model->employee_id : $model->getAttribute('employee_id'));

        return $employeeId !== null && Employee::query()->where('user_id', $user->id)->where('id', $employeeId)->exists();
    }
}
