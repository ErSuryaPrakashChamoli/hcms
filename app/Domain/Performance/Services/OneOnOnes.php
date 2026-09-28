<?php

namespace App\Domain\Performance\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Performance\Models\OneOnOne;
use RuntimeException;

/**
 * Phase 7: one-on-one private notes. They belong to the manager who held the meeting; the employee
 * never sees them (not even through the API or exports — the attribute is hidden and encrypted).
 * Anyone else needs performance.private_notes, and each read by someone else is audited.
 */
final class OneOnOnes
{
    public function __construct(private readonly AuditRecorder $audit, private readonly PerformanceRelationships $relationships) {}

    public function isOwningManager(OneOnOne $meeting, User $user): bool
    {
        $me = $this->relationships->forUser($user);

        return $me !== null && $meeting->manager_id !== null && (int) $meeting->manager_id === (int) $me->id && (int) $meeting->employee_id !== (int) $me->id;
    }

    public function canReadPrivateNotes(OneOnOne $meeting, User $user): bool
    {
        $me = $this->relationships->forUser($user);
        if ($me !== null && (int) $meeting->employee_id === (int) $me->id) {
            return false; // never the employee, whatever their permissions
        }

        return $this->isOwningManager($meeting, $user) || $user->hasPermission('performance.private_notes');
    }

    public function privateNotesFor(OneOnOne $meeting, User $user): ?string
    {
        if (! $this->canReadPrivateNotes($meeting, $user)) {
            return null;
        }
        if (! $this->isOwningManager($meeting, $user) && filled($meeting->private_notes)) {
            $this->audit->record(AuditAction::View, 'performance', $meeting, [], null, actor: $user, metadata: ['field' => 'private_notes']);
        }

        return $meeting->private_notes;
    }

    public function setPrivateNotes(OneOnOne $meeting, ?string $notes, User $user): OneOnOne
    {
        if (! $this->isOwningManager($meeting, $user)) {
            throw new RuntimeException('Only the manager who holds the one-on-one writes its private notes.');
        }
        $meeting->update(['private_notes' => $notes]);

        return $meeting;
    }
}
