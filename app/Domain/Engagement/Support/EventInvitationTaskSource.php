<?php

namespace App\Domain\Engagement\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Engagement\Models\CompanyEventInvitation;
use App\Domain\Engagement\Services\CompanyEvents;
use App\Domain\Experience\Contracts\ExperienceTaskSource;
use App\Domain\Experience\Support\ExperienceTask;
use App\Domain\Identity\Models\User;
use App\Filament\Pages\MyEvents;
use Illuminate\Support\Collection;
use Throwable;

/**
 * HROne parity GO-15 (matrix 27 §27.11 #20, HROne inbox "Engage: Events"; §27.8.3 #20 the invitation task): a "My tasks"
 * item for each invitation to a published event that is still waiting for the person's answer (invited, or nominated by
 * a colleague) while the event has not ended. "Acknowledge" (a mandatory event) or "Respond to" (an optional one); due
 * when the next session starts. Opens My events, where the person accepts or declines.
 */
final class EventInvitationTaskSource implements ExperienceTaskSource
{
    public function __construct(private readonly CompanyEvents $events) {}

    public function tasksFor(User $user, ?Employee $employee): Collection
    {
        if ($employee === null) {
            return collect();
        }

        return $this->events->pendingFor($employee)->map(function (CompanyEventInvitation $invitation) use ($employee) {
            $event = $invitation->event;
            $next = $this->events->nextSession($event);

            return new ExperienceTask('engagement', 'event_invitation', ($event->is_mandatory ? 'Acknowledge: ' : 'Respond to the invitation: ').$event->title,
                $next?->label($event->timezone), $next?->starts_at, $this->url(), ['employee_id' => (int) $employee->getKey(), 'requested_at' => $invitation->created_at]);
        })->values();
    }

    private function url(): ?string
    {
        try {
            return MyEvents::getUrl();
        } catch (Throwable) {
            return null;
        }
    }
}
