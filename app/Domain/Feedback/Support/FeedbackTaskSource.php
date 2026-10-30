<?php

namespace App\Domain\Feedback\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Contracts\ExperienceTaskSource;
use App\Domain\Experience\Support\ExperienceTask;
use App\Domain\Feedback\Models\FeedbackRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Filament\Resources\FeedbackRequests\FeedbackRequestResource;
use Illuminate\Support\Collection;

/**
 * HROne setup parity P13: "My tasks" items for the feedback asked of the person (exit interviews, confirmation feedback,
 * probation self-reviews, induction evaluations), directly or through a role they hold (never about themselves, except
 * their own interview or self-review). Due on the feedback's due date.
 */
final class FeedbackTaskSource implements ExperienceTaskSource
{
    public function tasksFor(User $user, ?Employee $employee): Collection
    {
        $roleIds = $user->roles()->pluck('roles.id')->all();
        $me = $employee?->id;

        return FeedbackRequest::query()->withoutGlobalScope(AccessScope::class)->where('status', 'pending')
            ->where(fn ($q) => $q->where('respondent_user_id', $user->id)
                ->orWhere(fn ($q) => $q->whereNull('respondent_user_id')->whereIn('respondent_role_id', $roleIds ?: [0])->where('employee_id', '!=', $me ?? 0)))
            ->orderBy('due_on')->orderBy('id')->limit(50)->get()
            ->map(fn (FeedbackRequest $r) => new ExperienceTask('feedback', $r->process, $r->title, $r->processLabel(), $r->due_on, FeedbackRequestResource::getUrl('index')));
    }
}
