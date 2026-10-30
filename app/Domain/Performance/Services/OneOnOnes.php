<?php

namespace App\Domain\Performance\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Performance\Models\OneOnOne;
use App\Domain\Performance\Models\OneOnOneFeedback;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Phase 7: one-on-one private notes. They belong to the manager who held the meeting; the employee
 * never sees them (not even through the API or exports — the attribute is hidden and encrypted).
 * Anyone else needs performance.private_notes, and each read by someone else is audited.
 *
 * HROne setup parity HP-8b: who may schedule with whom (performance.one_on_one.scope: team = the host's reports, as
 * before; organisation = anyone employed, the initiator hosting) and the feedback card each side named in
 * performance.one_on_one.feedback_from gives after a held meeting (also its acknowledgement).
 */
final class OneOnOnes
{
    /** A feedback card is asked for meetings held in the last days only. */
    public const FEEDBACK_DAYS = 30;

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

    public function scope(): string
    {
        return app(SettingsRepository::class)->get('performance.one_on_one.scope', 'team') === 'organisation' ? 'organisation' : 'team';
    }

    /**
     * Schedule a one-on-one. manager_id is the host (whose private notes they are), employee_id the participant.
     * Team scope: a non-administrator meets their own reports (as the form always offered). Organisation scope: any
     * employee with performance.goals or performance.team hosts a meeting with any employed colleague.
     *
     * @param  array<string, mixed>  $data
     */
    public function schedule(array $data, User $actor): OneOnOne
    {
        $me = $this->relationships->forUser($actor);
        $data['manager_id'] = $data['manager_id'] ?? $me?->id;
        if (empty($data['employee_id']) || empty($data['manager_id'])) {
            throw new RuntimeException('Choose who the one-on-one is with.');
        }
        if ((int) $data['employee_id'] === (int) $data['manager_id']) {
            throw new RuntimeException('A one-on-one is held with someone else.');
        }
        if (! $actor->hasPermission('performance.manage')) {
            if ($me === null) {
                throw new RuntimeException('Only employees schedule one-on-ones.');
            }
            if ($this->scope() === 'organisation') {
                if ((int) $data['manager_id'] !== (int) $me->id) {
                    throw new RuntimeException('You can only schedule one-on-ones you host.');
                }
                if (! $actor->hasPermission('performance.goals') && ! $actor->hasPermission('performance.team')) {
                    throw new RuntimeException('You cannot schedule one-on-ones.');
                }
                if (! Employee::query()->whereKey($data['employee_id'])->employed()->exists()) {
                    throw new RuntimeException('Choose a colleague who is employed.');
                }
            } elseif (! $this->relationships->manages($me, $data['employee_id'])) {
                throw new RuntimeException('You can schedule one-on-ones with your team members only.');
            }
        }
        $data['initiated_by'] = $me !== null && in_array((int) $me->id, [(int) $data['manager_id'], (int) $data['employee_id']], true) ? $me->id : $data['manager_id'];

        return OneOnOne::query()->create($data);
    }

    /** @return list<string> roles asked for a feedback card (initiator, participant) */
    public function feedbackRoles(): array
    {
        return array_values(array_intersect(array_keys(OneOnOneFeedback::ROLES), (array) app(SettingsRepository::class)->get('performance.one_on_one.feedback_from', [])));
    }

    /** The role an employee had in a meeting (initiator / participant), or null. */
    public function roleOf(OneOnOne $meeting, ?Employee $employee): ?string
    {
        if ($employee === null) {
            return null;
        }

        return match ((int) $employee->id) {
            (int) $meeting->initiatorId() => 'initiator',
            (int) $meeting->participantId() => 'participant',
            default => null,
        };
    }

    /** Whether the employee still owes the feedback card of a held meeting. */
    public function owesFeedback(OneOnOne $meeting, ?Employee $employee): bool
    {
        $role = $this->roleOf($meeting, $employee);

        return $meeting->status === 'held' && $role !== null && in_array($role, $this->feedbackRoles(), true)
            && ! OneOnOneFeedback::query()->withoutGlobalScope(AccessScope::class)->where('one_on_one_id', $meeting->id)->where('employee_id', $employee->id)->exists();
    }

    /**
     * Held meetings of the last FEEDBACK_DAYS days whose feedback card this employee still owes.
     *
     * @return Collection<int, OneOnOne>
     */
    public function feedbackDue(Employee $employee): Collection
    {
        if ($this->feedbackRoles() === []) {
            return collect();
        }

        return OneOnOne::query()->withoutGlobalScope(AccessScope::class)->with(['employee.person', 'manager.person'])->where('status', 'held')
            ->where(fn ($q) => $q->where('employee_id', $employee->id)->orWhere('manager_id', $employee->id))
            ->where('scheduled_at', '>=', now()->subDays(self::FEEDBACK_DAYS))
            ->whereDoesntHave('feedback', fn ($q) => $q->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id))
            ->orderByDesc('scheduled_at')->get()
            ->filter(fn (OneOnOne $meeting) => $this->owesFeedback($meeting, $employee))->values();
    }

    /** The feedback card: a 1–5 rating and a comment, once per person and meeting (it acknowledges the meeting). */
    public function giveFeedback(OneOnOne $meeting, User $actor, int $rating, ?string $comment = null): OneOnOneFeedback
    {
        $me = $this->relationships->forUser($actor);
        if (! $this->owesFeedback($meeting, $me)) {
            throw new RuntimeException('There is no feedback for you to give on this one-on-one.');
        }
        if ($rating < 1 || $rating > 5) {
            throw new RuntimeException('Rate the one-on-one from 1 to 5.');
        }

        return OneOnOneFeedback::query()->create([
            'one_on_one_id' => $meeting->id, 'employee_id' => $me->id, 'role' => $this->roleOf($meeting, $me),
            'rating' => $rating, 'comment' => $comment === null || trim($comment) === '' ? null : trim($comment), 'acknowledged_at' => now(),
        ]);
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
