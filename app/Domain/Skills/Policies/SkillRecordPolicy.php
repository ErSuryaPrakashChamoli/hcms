<?php

namespace App\Domain\Skills\Policies;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Performance\Services\PerformanceRelationships;
use Illuminate\Database\Eloquent\Model;

/**
 * Employee skills and skill assessments: skills.view sees all (private notes stay hidden), the
 * employee sees their own, a manager (skills.assess) the employees they manage. Writes go through
 * SkillProfiles / SkillAssessments, which re-check who may assess what.
 */
class SkillRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('skills.view') || $user->hasPermission('skills.manage') || $user->hasPermission('skills.assess') || $user->hasPermission('skills.self');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission('skills.view') || $user->hasPermission('skills.manage') || $this->isOwn($user, $model) || $this->isReport($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('skills.self') || $user->hasPermission('skills.assess') || $user->hasPermission('skills.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('skills.manage') || ($model->getAttribute('assessor_user_id') !== null && (int) $model->getAttribute('assessor_user_id') === (int) $user->id);
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    private function isOwn(User $user, Model $model): bool
    {
        return Employee::query()->where('user_id', $user->id)->whereKey($model->getAttribute('employee_id'))->exists();
    }

    private function isReport(User $user, Model $model): bool
    {
        $relationships = app(PerformanceRelationships::class);

        return $user->hasPermission('skills.assess') && $relationships->manages($relationships->forUser($user), $model->getAttribute('employee_id'));
    }
}
