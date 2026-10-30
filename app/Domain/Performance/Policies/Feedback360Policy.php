<?php

namespace App\Domain\Performance\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Performance\Models\FeedbackNomination;
use App\Domain\Performance\Models\FeedbackParticipant;
use App\Domain\Performance\Models\FeedbackRound;
use App\Domain\Performance\Services\FeedbackNominations;
use App\Domain\Performance\Services\FeedbackRoundResults;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne setup parity HP-8h: 360 feedback records. Rounds are HR's (performance.feedback_360 to run them; performance.view
 * to read them). A nomination set is seen by its nominator, its decider, HR in scope and whoever may read its results; a
 * nomination by the reviewer it asks and HR in scope. Answers (FeedbackResponse) are never reached through a policy: only
 * FeedbackRoundResults reads them, anonymity applied. Nothing is created or changed outside the services, and nothing is
 * ever deleted.
 */
class Feedback360Policy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('performance.feedback_360') || $user->hasPermission('performance.view') || $user->hasPermission('performance.review');
    }

    public function view(User $user, Model $model): bool
    {
        $hr = FeedbackRoundResults::isHr($user);

        return match (true) {
            $model instanceof FeedbackRound => $hr,
            $model instanceof FeedbackParticipant => ($hr && app(AccessScopes::class)->allows($user, $model)) || $model->isNominator($user)
                || app(FeedbackNominations::class)->mayDecide($user, $model) || app(FeedbackRoundResults::class)->canSee($user, $model),
            $model instanceof FeedbackNomination => (int) $model->reviewer_user_id === (int) $user->id || ($hr && app(AccessScopes::class)->allows($user, $model)),
            default => false,
        };
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('performance.feedback_360');
    }

    public function update(User $user, Model $model): bool
    {
        return false;
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
