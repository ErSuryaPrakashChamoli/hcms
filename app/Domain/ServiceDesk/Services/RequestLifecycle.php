<?php

namespace App\Domain\ServiceDesk\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Lifecycle\Services\Timeline;
use App\Domain\ServiceDesk\Events\ServiceDeskEvent;
use App\Domain\ServiceDesk\Exceptions\ServiceDeskRuleViolation;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Models\TicketTransition;
use Closure;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

/**
 * Phase 12: the only way a request's status (and its assignment, SLA and hand-off columns) changes.
 * Every change:
 * - locks the request row and checks the optimistic `lock_version` the caller saw;
 * - allows only the moves in `peopleos.servicedesk.transitions`;
 * - demands a reason where configured;
 * - stamps the lifecycle timestamps and pauses / resumes / restarts the SLA (SlaClock);
 * - appends a ticket_transitions row;
 * - writes exactly one audit event (REQUEST_* action, masked field diff, actor, reason);
 * - optionally dispatches a value-free event.
 *
 * One request per transaction: callers never move two requests inside one outer transaction (the
 * audit chain lock is taken last, after the request lock).
 */
final class RequestLifecycle
{
    public function __construct(private readonly AuditRecorder $audit, private readonly SlaClock $sla) {}

    public static function allowed(string $from, string $to): bool
    {
        return in_array($to, config("peopleos.servicedesk.transitions.{$from}", []), true);
    }

    /**
     * @param  string|null  $to  new status, or null to keep the status (assignment, hand-off …)
     * @param  array<string, mixed>  $changes  further columns to set
     * @param  Closure(Ticket): void|null  $guard  re-checks under the lock (throws to refuse)
     * @param  array{event?: string, context?: array<string, mixed>, recipients?: list<int>}  $notify
     */
    public function move(
        Ticket $ticket,
        ?string $to,
        ?User $actor,
        AuditAction $action,
        ?string $reason = null,
        array $changes = [],
        string $via = 'user',
        ?int $expectedVersion = null,
        ?Closure $guard = null,
        array $notify = [],
        array $metadata = [],
        bool $asWorker = false,
    ): Ticket {
        $fresh = DB::transaction(function () use ($ticket, $to, $actor, $action, $reason, $changes, $via, $expectedVersion, $guard, $metadata, $asWorker) {
            $fresh = Ticket::query()->withoutGlobalScope(AccessScope::class)->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
            if ($expectedVersion !== null && $fresh->lock_version !== $expectedVersion) {
                throw new ServiceDeskRuleViolation('This request changed since you opened it; reload and try again.');
            }
            $from = $fresh->status;
            // HR access is checked again under the row lock: a grant revoked (or a scope changed) after
            // the caller's first check cannot let the change through.
            if ($asWorker && ($actor === null || ! app(CaseAccess::class)->canWork($actor, $fresh))) {
                throw new ServiceDeskRuleViolation('You cannot work this case.');
            }
            if ($to !== null && $to === $from) {
                throw new ServiceDeskRuleViolation('The request is already '.$this->label($from).'.');
            }
            if ($guard) {
                $guard($fresh);
                // A guard may choose the status (e.g. assigning moves submitted → assigned): it is still a
                // validated transition with its SLA, history and audit.
                if ($to === null && $fresh->status !== $from) {
                    $to = $fresh->status;
                    $fresh->status = $from;
                }
            }
            if ($to !== null && $to !== $from) {
                if (! self::allowed($from, $to)) {
                    throw new ServiceDeskRuleViolation('A '.$this->label($from).' request cannot move to '.$this->label($to).'.');
                }
                $reopen = in_array($from, ['resolved', 'closed'], true) && $to === 'in_progress';
                $required = config('peopleos.servicedesk.reason_required', []);
                if (blank($reason) && (in_array($to, $required, true) || ($reopen && in_array('reopen', $required, true)) || $to === 'resolved')) {
                    throw new ServiceDeskRuleViolation($to === 'resolved' ? 'A resolution is required.' : 'A reason is required.');
                }
                $changes = $this->stamp($fresh, $from, $to, $actor, $reopen) + $changes;
            }
            $fresh->fill($changes);
            $fresh->status_changed_at = $to !== null && $to !== $from ? now() : $fresh->status_changed_at;
            if ($to !== null && $to !== $from) {
                $fresh->status = $to;
                $this->sla->onTransition($fresh, $from, $to);
            }
            $diff = $this->diff($fresh);
            if ($diff === [] && ($to === null || $to === $from)) {
                return $fresh;
            }
            $fresh->lock_version = $fresh->lock_version + 1;
            $fresh->withoutAuditing()->save();
            if ($to !== null && $to !== $from) {
                TicketTransition::query()->create(['ticket_id' => $fresh->id, 'from_status' => $from, 'to_status' => $to, 'actor_id' => $actor?->id, 'via' => $via, 'reason' => $reason, 'operation_id' => Context::get('audit.operation_id')]);
            }
            $this->audit->record($action, 'servicedesk', $fresh, $diff, $reason, actor: $actor, metadata: array_filter(['from' => $from, 'to' => $to ?? $from, 'via' => $via, 'correlation_id' => $fresh->correlation_id] + $metadata, fn ($v) => $v !== null));
            if (in_array($to, ['resolved', 'cancelled'], true) && $to !== $from && $fresh->confidentiality === 'standard' && $fresh->visible_to_employee) {
                // Employee 360 timeline: a reference to the request, never its content.
                $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->find($fresh->employee_id);
                if ($employee !== null) {
                    app(Timeline::class)->record($employee, 'service_request', 'HR request '.$fresh->number.' '.($to === 'resolved' ? 'resolved' : 'cancelled').': '.$fresh->serviceName(), now(), null, $fresh, ['number' => $fresh->number, 'status' => $to]);
                }
            }

            return $fresh;
        });

        $ticket->setRawAttributes($fresh->getAttributes(), true);
        if (($notify['event'] ?? null) !== null) {
            ServiceDeskEvent::dispatch($notify['event'], $ticket, $this->context($ticket) + ($notify['context'] ?? []), array_values(array_unique(array_filter($notify['recipients'] ?? []))));
        }

        return $ticket;
    }

    /** Value-free event context: number, service, status, priority — never subject, form data or text. */
    public function context(Ticket $ticket): array
    {
        return ['number' => $ticket->number, 'service' => $ticket->serviceName(), 'service_code' => $ticket->serviceCode(), 'status' => $ticket->status, 'priority' => $ticket->priority, 'employee_id' => $ticket->employee_id];
    }

    public function label(string $status): string
    {
        return strtolower((string) config("peopleos.servicedesk.statuses.{$status}", $status));
    }

    /** @return array<string, mixed> lifecycle timestamps for a move */
    private function stamp(Ticket $ticket, string $from, string $to, ?User $actor, bool $reopen): array
    {
        $now = now();

        return array_filter([
            'submitted_at' => $to === 'submitted' && $ticket->submitted_at === null ? $now : null,
            'acknowledged_at' => $to === 'acknowledged' && $ticket->acknowledged_at === null ? $now : null,
            'first_responded_at' => in_array($to, ['acknowledged', 'in_progress', 'waiting_employee', 'resolved'], true) && $ticket->first_responded_at === null && $from !== 'draft' ? $now : null,
            'resolved_at' => $to === 'resolved' ? $now : null,
            'resolved_by' => $to === 'resolved' ? $actor?->id : null,
            'closed_at' => $to === 'closed' ? $now : null,
            'cancelled_at' => $to === 'cancelled' ? $now : null,
        ], fn ($v) => $v !== null) + ($reopen ? ['resolved_at' => null, 'resolved_by' => null, 'closed_at' => null, 'escalation_level' => 0, 'escalated_at' => null] : []);
    }

    /** @return list<array{field: string, before: mixed, after: mixed, sensitive: bool}> */
    private function diff(Ticket $ticket): array
    {
        $sensitive = $ticket->auditSensitiveAttributes();
        $changes = [];
        foreach ($ticket->getDirty() as $field => $after) {
            if (in_array($field, ['updated_at', 'lock_version', 'status_changed_at'], true)) {
                continue;
            }
            $before = $ticket->getRawOriginal($field);
            $changes[] = ['field' => $field, 'before' => in_array($field, $sensitive, true) ? ($before === null ? null : '[set]') : $before, 'after' => in_array($field, $sensitive, true) ? ($after === null ? null : '[set]') : $after, 'sensitive' => in_array($field, $sensitive, true)];
        }

        return $changes;
    }
}
