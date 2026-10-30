<?php

namespace App\Domain\Attendance\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Shifts, schedules, holidays, devices: view with attendance.view, change with attendance.manage (punches: attendance.punch). */
class AttendanceConfigPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('attendance.view');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission('attendance.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('attendance.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('attendance.manage');
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->hasPermission('attendance.manage');
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasPermission('attendance.manage');
    }

    /** HROne parity TX-2: punches (record, retry, upload) need attendance.punch, which attendance.manage also grants. */
    public function punch(User $user, ?Model $model = null): bool
    {
        return $user->hasPermission('attendance.punch');
    }
}
