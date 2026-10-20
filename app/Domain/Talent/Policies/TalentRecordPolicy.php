<?php

namespace App\Domain\Talent\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Talent\Services\TalentAccess;
use Illuminate\Database\Eloquent\Model;

/**
 * Talent profiles, pool memberships, talent assessments, review items and development-action
 * links. Never visible to the employee themself and never to a manager by relationship alone:
 * talent.view (read) / talent.manage (write) within organisation scope. Confidential attributes are
 * hidden on the models and read only through TalentAccess::confidential (audited).
 */
class TalentRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('talent.view') || $user->hasPermission('talent.manage');
    }

    public function view(User $user, Model $model): bool
    {
        $access = app(TalentAccess::class);

        return $access->mayViewTalent($user, $model->getAttribute('employee_id')) || $access->mayManageTalent($user, $model->getAttribute('employee_id'));
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('talent.manage') || $user->hasPermission('talent.assess');
    }

    public function update(User $user, Model $model): bool
    {
        return app(TalentAccess::class)->mayManageTalent($user, $model->getAttribute('employee_id'));
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
