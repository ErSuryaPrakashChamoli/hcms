<?php

namespace App\Domain\Career\Policies;

use App\Domain\Career\Models\CareerAspirationEntry;
use App\Domain\Career\Models\CareerGoal;
use App\Domain\Career\Models\MobilityInterest;
use App\Domain\Identity\Models\User;
use App\Domain\Talent\Services\TalentAccess;
use Illuminate\Database\Eloquent\Model;

/**
 * Career profiles, aspirations, career goals and mobility interest. The employee sees and keeps
 * their own; a manager (career.team, configured relationship types only) sees only what the
 * employee shares; career.view / career.manage within organisation scope. Writes go through
 * CareerProfiles, which re-checks the same rules.
 */
class CareerRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('career.view') || $user->hasPermission('career.manage') || $user->hasPermission('career.team') || $user->hasPermission('career.self');
    }

    public function view(User $user, Model $model): bool
    {
        $field = match (true) {
            $model instanceof CareerAspirationEntry => 'aspirations',
            $model instanceof MobilityInterest => 'mobility',
            $model instanceof CareerGoal => 'goals',
            default => 'profile',
        };

        return app(TalentAccess::class)->mayViewCareer($user, $model->getAttribute('employee_id'), $field);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('career.self') || $user->hasPermission('career.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return app(TalentAccess::class)->mayEditCareer($user, $model->getAttribute('employee_id'));
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
