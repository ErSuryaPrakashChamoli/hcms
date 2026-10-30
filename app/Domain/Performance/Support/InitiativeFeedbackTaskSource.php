<?php

namespace App\Domain\Performance\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Contracts\ExperienceTaskSource;
use App\Domain\Experience\Support\ExperienceTask;
use App\Domain\Identity\Models\User;
use App\Domain\Performance\Models\InitiativeFeedback;
use App\Domain\Performance\Services\Initiatives;
use App\Filament\Resources\Initiatives\InitiativeResource;
use Illuminate\Support\Collection;

/**
 * HROne setup parity HP-8j: "My tasks" items for feedback on a colleague's initiative asked of the person (HROne's
 * initiative inbox). No due date: an unanswered request never counts as overdue.
 */
final class InitiativeFeedbackTaskSource implements ExperienceTaskSource
{
    public function __construct(private readonly Initiatives $initiatives) {}

    public function tasksFor(User $user, ?Employee $employee): Collection
    {
        if ($employee === null) {
            return collect();
        }

        return $this->initiatives->pendingFeedbackFor($employee)->map(fn (InitiativeFeedback $feedback) => new ExperienceTask(
            'performance', 'initiative_feedback',
            'Feedback on '.($feedback->initiative?->employee?->person?->full_name ?? 'a colleague').'\'s initiative: '.$feedback->initiative?->title,
            $feedback->requested_at?->toDateString(), null, InitiativeResource::getUrl('index', ['tab' => 'feedback']),
        ));
    }
}
