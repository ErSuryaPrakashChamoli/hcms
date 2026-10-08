<?php

namespace App\Domain\Experience\Services;

use App\Domain\Identity\Models\User;
use App\Filament\Resources\Employees\EmployeeResource;

/**
 * UX.15: the Peek level of Peek → Drawer → Workspace. A small card with directory fields only (name,
 * role, team, location, manager), for people the viewer may already find (PeopleVisibility). An id the
 * viewer may not see answers exactly like a missing one (null), so a peek never confirms a record exists.
 * The lifecycle status and the profile link appear only when the existing EmployeePolicy::view allows the
 * full Employee 360.
 */
final class PersonPeek
{
    public function __construct(private readonly PeopleVisibility $visibility) {}

    /** @return array{id: int, name: string, initials: string, tone: int, title: ?string, team: ?string, location: ?string, manager: ?string, status: ?string, profile: ?string}|null */
    public function for(User $viewer, int $employeeId): ?array
    {
        $e = $this->visibility->query($viewer)
            ->with(['person', 'currentPosition.designation', 'currentPosition.department', 'currentPosition.location', 'currentManager.manager.person'])
            ->find($employeeId);
        if ($e === null) {
            return null;
        }
        $open = $this->visibility->canOpenProfile($viewer, $e);
        $p = $e->currentPosition;

        $name = (string) ($e->display_name ?? $e->employee_code);

        return [
            'id' => $e->id,
            'name' => $name,
            // Same initials and tint as the avatar component, so the peek matches the chip it came from.
            'initials' => collect(preg_split('/\s+/', trim($name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('') ?: '·',
            'tone' => (crc32($name) % 4) + 1,
            'title' => $p?->designation?->name,
            'team' => $p?->department?->name,
            'location' => $p?->location?->name,
            'manager' => $e->currentManager?->manager?->person?->display_name,
            'status' => $open ? $e->lifecycle_state?->getLabel() : null,
            'profile' => $open ? EmployeeResource::getUrl('view', ['record' => $e]) : null,
        ];
    }
}
