<?php

namespace App\Domain\ServiceDesk\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\ServiceDesk\Exceptions\ServiceDeskRuleViolation;
use App\Domain\ServiceDesk\Models\Ticket;
use Throwable;

/**
 * Phase 12: bulk case operations (assign, move status, escalate).
 *
 * Each run:
 * - has an operation id (AuditRecorder::operation), so every audit event inside carries it and a
 *   BULK_OPERATION summary closes the run;
 * - needs servicedesk.bulk;
 * - authorises every case individually (CaseAccess) and moves it in its own transaction through the
 *   same lifecycle;
 * - is idempotent: a case already in the requested state is skipped;
 * - reports every case's outcome: done, skipped or refused with the reason. Nothing fails silently.
 */
final class ServiceDeskBulk
{
    public function __construct(private readonly AuditRecorder $audit, private readonly CaseAccess $access, private readonly CaseAssignment $assignment, private readonly RequestLifecycle $lifecycle) {}

    /**
     * @param  list<int>  $ids
     * @return array{operation_id: string, results: array<int, string>}
     */
    public function assign(array $ids, User $agent, User $actor, ?string $reason = null): array
    {
        return $this->run('Bulk assign', $ids, $actor, $reason, function (Ticket $ticket) use ($agent, $actor, $reason) {
            if ((int) $ticket->assignee_id === (int) $agent->id) {
                return 'skipped: already assigned';
            }
            $this->assignment->assign($ticket, $agent, $actor, $reason);

            return 'done';
        });
    }

    /** @param  list<int>  $ids */
    public function move(array $ids, string $to, User $actor, ?string $reason = null): array
    {
        if (! array_key_exists($to, config('peopleos.servicedesk.statuses')) || in_array($to, ['draft', 'resolved', 'closed', 'cancelled', 'awaiting_approval'], true)) {
            throw new ServiceDeskRuleViolation('Bulk status moves are limited to working statuses (resolve, close and cancel each case individually).');
        }

        return $this->run('Bulk status: '.$to, $ids, $actor, $reason, function (Ticket $ticket) use ($to, $actor, $reason) {
            if ($ticket->status === $to) {
                return 'skipped: already '.$to;
            }
            if (! $this->access->canWork($actor, $ticket)) {
                throw new ServiceDeskRuleViolation('You cannot work this case.');
            }
            $this->lifecycle->move($ticket, $to, $actor, AuditAction::RequestStatusChanged, $reason, [], 'bulk');

            return 'done';
        });
    }

    /** @param  list<int>  $ids */
    public function escalate(array $ids, User $actor, string $reason): array
    {
        return $this->run('Bulk escalate', $ids, $actor, $reason, function (Ticket $ticket) use ($actor, $reason) {
            if (! $this->access->canWork($actor, $ticket) || ! $ticket->isOpen()) {
                throw new ServiceDeskRuleViolation('You cannot escalate this case.');
            }
            $this->lifecycle->move($ticket, null, $actor, AuditAction::RequestEscalated, $reason, ['escalation_level' => (int) $ticket->escalation_level + 1, 'escalated_at' => now()], 'bulk',
                notify: ['event' => 'servicedesk.ticket.escalated', 'recipients' => array_filter([$ticket->assignee_id])]);

            return 'done';
        });
    }

    /** @param  list<int>  $ids */
    private function run(string $label, array $ids, User $actor, ?string $reason, callable $each): array
    {
        if (! $actor->hasPermission('servicedesk.bulk')) {
            throw new ServiceDeskRuleViolation('This needs servicedesk.bulk.');
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (count($ids) > 500) {
            throw new ServiceDeskRuleViolation('Run a bulk operation on at most 500 cases.');
        }
        $results = [];
        $operation = $this->audit->operation('servicedesk', $label, function () use ($ids, $actor, $each, &$results) {
            $ok = 0;
            $failed = 0;
            $visible = $this->access->visible(Ticket::query(), $actor)->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
            foreach ($ids as $id) {
                if (! in_array($id, $visible, true)) {
                    $results[$id] = 'refused: not found';
                    $failed++;

                    continue;
                }
                try {
                    $results[$id] = $each(Ticket::query()->withoutGlobalScope(AccessScope::class)->findOrFail($id));
                    str_starts_with($results[$id], 'done') ? $ok++ : null;
                } catch (ServiceDeskRuleViolation $e) {
                    $results[$id] = 'refused: '.$e->getMessage();
                    $failed++;
                } catch (Throwable $e) {
                    report($e);
                    $results[$id] = 'refused: '.$e->getMessage();
                    $failed++;
                }
            }

            return ['succeeded' => $ok, 'failed' => $failed, 'ids' => $ids];
        }, $reason, Ticket::class);

        return ['operation_id' => $operation, 'results' => $results];
    }
}
