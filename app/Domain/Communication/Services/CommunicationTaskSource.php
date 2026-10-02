<?php

namespace App\Domain\Communication\Services;

use App\Domain\Communication\Models\Announcement;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Contracts\ExperienceTaskSource;
use App\Domain\Experience\Support\ExperienceTask;
use App\Domain\Identity\Models\User;
use App\Filament\Pages\MyHr;
use Illuminate\Support\Collection;

/** Phase 13: announcements waiting for the employee's acknowledgement, as My HR task references. */
final class CommunicationTaskSource implements ExperienceTaskSource
{
    public function __construct(private readonly Communications $communications) {}

    public function tasksFor(User $user, ?Employee $employee): Collection
    {
        if ($employee === null || ! $user->hasPermission('communication.view')) {
            return collect();
        }

        return $this->communications->pendingAcknowledgements($employee)->map(fn (Announcement $a) => new ExperienceTask(
            'communication', 'acknowledge', 'Acknowledge: '.$a->title, $a->version > 1 ? 'v'.$a->version : null, $a->expires_at, MyHr::getUrl(['tab' => 'communications']),
        ))->values();
    }
}
