<?php

namespace App\Domain\Attendance\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Identity\Services\OwnRecords;
use Illuminate\Database\Eloquent\Model;

class AttendanceRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('attendance.view') || $user->hasPermission('attendance.regularise');
    }

    public function view(User $user, Model $model): bool
    {
        return ($user->hasPermission('attendance.view') && $this->inScope($user, $model)) || $this->isOwn($user, $model);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('attendance.manage') && $this->inScope($user, $model);
    }

    /** Phase 12: approvers never approve their own attendance. */
    public function approve(User $user, Model $model): bool
    {
        return $user->hasPermission('attendance.approve') && $this->inScope($user, $model) && ! $this->isOwn($user, $model);
    }

    public function regularise(User $user, Model $model): bool
    {
        return ($user->hasPermission('attendance.manage') && $this->inScope($user, $model)) || ($user->hasPermission('attendance.regularise') && $this->isOwn($user, $model));
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    /** Organisation + relationship scope (ADR-0004): managers reach direct reports, HR reaches their scope. */
    private function inScope(User $user, Model $model): bool
    {
        return app(AccessScopes::class)->allows($user, $model);
    }

    private function isOwn(User $user, Model $model): bool
    {
        // Same query as before; inside an authorisation pass the viewer's own ids are read once (OwnRecords).
        return app(OwnRecords::class)->isOwn($user, $model->employee_id);
    }
}
