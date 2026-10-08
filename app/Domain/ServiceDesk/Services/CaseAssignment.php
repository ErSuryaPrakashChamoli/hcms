<?php

namespace App\Domain\ServiceDesk\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\ServiceDesk\Exceptions\ServiceDeskRuleViolation;
use App\Domain\ServiceDesk\Models\ServiceDefinitionVersion;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Models\TicketCategory;
use Illuminate\Support\Collection;

/**
 * Phase 12: who works a case. The team is a role, the agent a user, and the case owner the person
 * accountable for it.
 *
 * Holding an HR role is never enough. An agent must be:
 * - active;
 * - a holder of servicedesk.agent;
 * - in the employee's organisation scope;
 * - not the case's own subject;
 * - for restricted cases, a holder of servicedesk.confidential.
 *
 * Assignment:
 * - Claim, assign, reassign and unassign run under the request lock (RequestLifecycle). A claim
 *   succeeds only while the case is unassigned.
 * - Auto-assignment picks the service's (or legacy category's) default agent if eligible, else the
 *   least-loaded eligible member of the team.
 */
final class CaseAssignment
{
    public function __construct(private readonly RequestLifecycle $lifecycle, private readonly CaseAccess $access) {}

    public function assign(Ticket $ticket, User $agent, User $actor, ?string $reason = null, ?int $expectedVersion = null): Ticket
    {
        if (! $this->access->canWork($actor, $ticket)) {
            throw new ServiceDeskRuleViolation('You cannot work this case.');
        }

        return $this->setAgent($ticket, $agent, $actor, $reason, $expectedVersion);
    }

    /** Take an unassigned case. Two agents claiming at once: exactly one wins (checked under the lock). */
    public function claim(Ticket $ticket, User $agent, ?int $expectedVersion = null): Ticket
    {
        if (! $this->access->canWork($agent, $ticket) || ! $this->access->eligibleAgent($agent, $ticket)) {
            throw new ServiceDeskRuleViolation('You cannot work this case.');
        }

        return $this->setAgent($ticket, $agent, $agent, null, $expectedVersion, claim: true);
    }

    public function unassign(Ticket $ticket, User $actor, ?string $reason = null, ?int $expectedVersion = null): Ticket
    {
        if (! $this->access->canWork($actor, $ticket)) {
            throw new ServiceDeskRuleViolation('You cannot work this case.');
        }

        return $this->lifecycle->move($ticket, null, $actor, AuditAction::RequestReassigned, $reason, [], 'user', $expectedVersion,
            guard: function (Ticket $fresh) {
                if ($fresh->assignee_id === null) {
                    throw new ServiceDeskRuleViolation('The case is not assigned.');
                }
                $fresh->fill(['assignee_id' => null, 'assigned_at' => null]);
                if ($fresh->status === 'assigned') {
                    $fresh->status = $fresh->acknowledged_at ? 'acknowledged' : 'submitted';
                }
            },
            metadata: ['event' => 'REQUEST_UNASSIGNED'],
        );
    }

    public function assignTeam(Ticket $ticket, Role $team, User $actor, ?string $reason = null): Ticket
    {
        if (! $this->access->canWork($actor, $ticket)) {
            throw new ServiceDeskRuleViolation('You cannot work this case.');
        }

        return $this->lifecycle->move($ticket, null, $actor, AuditAction::RequestReassigned, $reason, ['assigned_role_id' => $team->id], 'user',
            guard: fn (Ticket $fresh) => $fresh->isOpen() ?: throw new ServiceDeskRuleViolation('Only an open request can change team.'),
            notify: ['event' => 'servicedesk.ticket.reassigned', 'recipients' => $this->eligibleMembers($team, $ticket)->take(25)->pluck('id')->all()],
        );
    }

    /** Default team and agent for a new request (called inside the submission transaction). */
    public function initial(Ticket $ticket, ?ServiceDefinitionVersion $version, ?TicketCategory $category): void
    {
        $roleId = data_get($version?->assignment, 'role_id') ?: $category?->assignee_role_id;
        $userId = data_get($version?->assignment, 'user_id') ?: $category?->default_assignee_id;
        $ticket->assigned_role_id = $roleId ? (int) $roleId : null;
        $agent = $userId ? User::query()->find((int) $userId) : null;
        if ($agent !== null && ! $this->access->eligibleAgent($agent, $ticket)) {
            $agent = null;
        }
        if ($agent === null && $roleId) {
            $agent = $this->leastLoaded($this->eligibleMembers(Role::query()->find((int) $roleId), $ticket));
        }
        if ($agent !== null) {
            $ticket->assignee_id = $agent->id;
            $ticket->assigned_at = now();
        }
    }

    /** @return Collection<int, User> agents who may be given this case (assignment pickers) */
    public function eligibleAgents(Ticket $ticket, ?Role $team = null): Collection
    {
        $pool = $team ? $team->users()->get() : User::query()->forCurrentTenant()->get();

        return $pool->filter(fn (User $u) => $this->access->eligibleAgent($u, $ticket))->values();
    }

    /** @return Collection<int, User> */
    public function eligibleMembers(?Role $team, Ticket $ticket): Collection
    {
        return $team ? $team->users()->get()->filter(fn (User $u) => $this->access->eligibleAgent($u, $ticket))->values() : collect();
    }

    private function setAgent(Ticket $ticket, User $agent, User $actor, ?string $reason, ?int $expectedVersion, bool $claim = false): Ticket
    {
        $reassign = $ticket->assignee_id !== null && (int) $ticket->assignee_id !== (int) $agent->id;

        return $this->lifecycle->move($ticket, null, $actor, $reassign ? AuditAction::RequestReassigned : AuditAction::RequestAssigned, $reason, [], 'user', $expectedVersion,
            guard: function (Ticket $fresh) use ($agent, $claim) {
                if (! $fresh->isOpen()) {
                    throw new ServiceDeskRuleViolation('Only an open request can be assigned.');
                }
                if (! $this->access->eligibleAgent($agent, $fresh)) {
                    throw new ServiceDeskRuleViolation($agent->name.' cannot work this case (permission or organisation scope).');
                }
                if ($claim && $fresh->assignee_id !== null && (int) $fresh->assignee_id !== (int) $agent->id) {
                    throw new ServiceDeskRuleViolation('Someone else has already taken this case.');
                }
                $fresh->fill(['assignee_id' => $agent->id, 'assigned_at' => now()]);
                if (in_array($fresh->status, ['submitted', 'acknowledged'], true)) {
                    $fresh->status = 'assigned';
                }
            },
            notify: ['event' => $reassign ? 'servicedesk.ticket.reassigned' : 'servicedesk.ticket.assigned', 'recipients' => (int) $actor->id === (int) $agent->id ? [] : [$agent->id]],
            metadata: ['event' => $claim ? 'REQUEST_CLAIMED' : ($reassign ? 'REQUEST_REASSIGNED' : 'REQUEST_ASSIGNED')],
            asWorker: true,
        );
    }

    private function leastLoaded(Collection $candidates): ?User
    {
        if ($candidates->isEmpty()) {
            return null;
        }
        $load = Ticket::query()->withoutGlobalScope(AccessScope::class)
            ->whereIn('assignee_id', $candidates->pluck('id'))->whereIn('status', Ticket::OPEN)
            ->selectRaw('assignee_id, count(*) as open_count')->groupBy('assignee_id')->pluck('open_count', 'assignee_id');

        return $candidates->sortBy(fn (User $u) => [(int) ($load[$u->id] ?? 0), $u->id])->first();
    }
}
