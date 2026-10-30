<?php

namespace App\Domain\Performance\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Contracts\ExperienceTaskSource;
use App\Domain\Experience\Support\ExperienceTask;
use App\Domain\Identity\Models\User;
use App\Domain\Performance\Models\OneOnOne;
use App\Domain\Performance\Services\OneOnOnes;
use App\Filament\Resources\OneOnOnes\OneOnOneResource;
use Illuminate\Support\Collection;

/**
 * HROne setup parity HP-8b: "My tasks" items for the feedback card of a held one-on-one, for the roles named in
 * performance.one_on_one.feedback_from (none by default). Due a day after the meeting.
 */
final class OneOnOneFeedbackTaskSource implements ExperienceTaskSource
{
    public function __construct(private readonly OneOnOnes $oneOnOnes) {}

    public function tasksFor(User $user, ?Employee $employee): Collection
    {
        if ($employee === null) {
            return collect();
        }

        return $this->oneOnOnes->feedbackDue($employee)->map(function (OneOnOne $meeting) use ($employee) {
            $other = (int) $meeting->employee_id === (int) $employee->id ? $meeting->manager : $meeting->employee;

            return new ExperienceTask(
                'performance', 'one_on_one_feedback', PerformanceLabels::get('feedback').' on your one-on-one with '.($other?->person?->full_name ?? 'a colleague'),
                $meeting->scheduled_at?->toDateString(), $meeting->scheduled_at?->copy()->addDay(), OneOnOneResource::getUrl('index'),
            );
        });
    }
}
