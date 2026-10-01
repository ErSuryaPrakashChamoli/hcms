<?php

namespace App\Domain\Learning\Policies;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Performance\Services\PerformanceRelationships;
use Illuminate\Database\Eloquent\Model;

/**
 * Enrolments, certificates, completions, evidence, program participations: L&D staff see all
 * (learning.view / learning.manage), learners their own, managers (learning.team or
 * learning.assign) the employees they manage through a configured relationship type (Phase 8:
 * the PeopleOS relationship resolver, never every reporting line). Organisation scope is applied
 * underneath by the access scope on these models.
 */
class EnrolmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('learning.view') || $user->hasPermission('learning.manage') || $user->hasPermission('learning.assign') || $user->hasPermission('learning.team') || $user->hasPermission('learning.learn');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission('learning.view') || $user->hasPermission('learning.manage') || self::isOwn($user, $model) || self::isReport($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('learning.assign') || $user->hasPermission('learning.manage');
    }

    /** Learners act on their own learning; managers on their reports'; L&D on everyone's. Finalized records are guarded by the models. */
    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('learning.manage') || self::isOwn($user, $model) || ($user->hasPermission('learning.assign') && self::isReport($user, $model));
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public static function isOwn(User $user, Model $model): bool
    {
        return $model->getAttribute('employee_id') !== null && Employee::query()->where('user_id', $user->id)->where('id', $model->getAttribute('employee_id'))->exists();
    }

    public static function isReport(User $user, Model $model): bool
    {
        if (! ($user->hasPermission('learning.team') || $user->hasPermission('learning.assign')) || $model->getAttribute('employee_id') === null) {
            return false;
        }
        $relationships = app(PerformanceRelationships::class);

        return $relationships->manages($relationships->forUser($user), $model->getAttribute('employee_id'));
    }
}
