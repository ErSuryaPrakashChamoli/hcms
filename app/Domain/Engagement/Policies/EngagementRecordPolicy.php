<?php

namespace App\Domain\Engagement\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 13: participations, responses, answers and confidential identities have no generic UI or
 * API access at all. They are reached only through SurveyResponses / EngagementAnalytics /
 * ConfidentialIdentities, which enforce the anonymity rules.
 */
class EngagementRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, Model $model): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Model $model): bool
    {
        return false;
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
