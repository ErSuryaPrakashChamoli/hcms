<?php

namespace App\Domain\Experience\Services;

use App\Domain\Attendance\Services\Regularisations;
use App\Domain\Compensation\Services\CompensationChanges;
use App\Domain\Experience\Models\InboxDecision;
use App\Domain\Experience\Support\ApprovalItem;
use App\Domain\Experience\Support\InboxActions;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Services\Leaves;
use App\Domain\Letters\Services\Letters;
use App\Domain\Workflow\Services\RequestReturns;
use App\Domain\Workflow\Services\TaskDelegations;
use App\Domain\Workflow\Services\WorkflowEngine;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * UX: sends an Approval Center decision to the service that owns the record. The item is re-resolved
 * and re-authorised on the server (ApprovalCenter::find), the decision must be one the item offers, and
 * the domain service applies its own rules again (status, separation of duties, balances, locks).
 * Nothing here changes business state directly.
 */
final class ApprovalDecisions
{
    public function __construct(private readonly ApprovalCenter $center, private readonly UxMetrics $metrics) {}

    /**
     * @return string a short confirmation for the person
     *
     * @throws AuthorizationException when the item is not (or no longer) decidable by the user
     * @throws InvalidArgumentException when the decision needs a reason that is missing
     * @throws \RuntimeException from the domain service (already decided, balance, duties…)
     */
    public function decide(User $user, string $id, string $decision, ?string $note = null): string
    {
        return $this->decideItem($user, $this->center->find($user, $id), $decision, $note);
    }

    /** HROne setup parity P3: the most items one bulk approval takes (the queue renders 25 at a time). */
    public const BULK_MAX = 100;

    /**
     * HROne setup parity P3 (Inbox → Bulk approval): approve several items at once. Only items whose request type is in
     * inbox.bulk_approval.types are taken; the queue is re-resolved and re-authorised once, and each item is decided on
     * its own (its record read again, its own transaction and audit entry through the owning service), so one refusal
     * does not stop the others. Rejecting stays one at a time, with a reason.
     *
     * @param  list<string>  $ids
     * @return array{approved: array<string, string>, failed: array<string, string>} item id => confirmation / reason
     */
    public function approveMany(User $user, array $ids, ?string $note = null): array
    {
        $ids = array_slice(array_values(array_unique(array_filter($ids, 'is_string'))), 0, self::BULK_MAX);
        $result = ['approved' => [], 'failed' => []];
        $this->center->forget($user);
        $pending = $this->center->pending($user)->keyBy('id');
        foreach ($ids as $id) {
            $item = $pending->get($id);
            if ($item === null || ! InboxActions::enabled('bulk_approval', $item->type) || ! $item->can('approve')) {
                $result['failed'][$id] = $item === null ? 'No longer waiting for your decision.' : $item->title.': bulk approval is not switched on for '.mb_strtolower($item->typeLabel).'.';

                continue;
            }
            try {
                $item->record->refresh();
                $result['approved'][$id] = DB::transaction(fn () => $this->decideItem($user, $item, 'approve', $note));
            } catch (Throwable $e) {
                if (! $e instanceof AuthorizationException && ! $e instanceof \RuntimeException && ! $e instanceof InvalidArgumentException) {
                    report($e);
                }
                $result['failed'][$id] = $item->title.': '.$e->getMessage();
            }
        }
        $this->center->forget($user);

        return $result;
    }

    private function decideItem(User $user, ?ApprovalItem $item, string $decision, ?string $note): string
    {
        if ($item === null || ! $item->can($decision)) {
            $this->metrics->record('approval.failed');

            throw new AuthorizationException('This item is no longer waiting for your decision.');
        }
        $note = filled($note) ? trim((string) $note) : null;
        if ($item->needsNote($decision) && $note === null) {
            throw new InvalidArgumentException('Add a reason so the requester knows what to do next.');
        }

        // HROne setup parity P3: sending back returns the request to its requester (RequestReturns checks it is offered).
        $act = fn (User $as) => $decision === 'send_back'
            ? tap('Sent back to the requester', fn () => app(RequestReturns::class)->sendBack($item, $as, (string) $note))
            : $this->apply($item, $as, $decision, $note);
        $message = $item->isDelegated() ? $this->onBehalf($item, $user, $act) : $act($user);
        $this->center->forget($user);
        $this->metrics->record('approval.decided');
        $this->logDecision($user, $item, $decision);

        return $message;
    }

    /**
     * HROne parity RQ-8 (Inbox → Insight): remember who decided a domain request here and the due time the item showed, for
     * the approver's own on-time / delayed counts. Workflow steps are counted from their task. The decision itself is
     * audited by the owning service; a failure to log never undoes or hides it.
     */
    private function logDecision(User $user, ApprovalItem $item, string $decision): void
    {
        if ($item->type === 'workflow_task') {
            return;
        }
        try {
            InboxDecision::query()->create([
                'user_id' => $user->id, 'on_behalf_of_user_id' => $item->onBehalfOfUserId, 'item_id' => mb_substr($item->id, 0, 80),
                'item_type' => mb_substr($item->type, 0, 40), 'decision' => $decision, 'due_at' => $item->dueAt, 'decided_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * HROne setup parity P4 (out of office): an item reaching the person through a delegation is decided in the delegator's
     * stead — the delegation is checked again (still running, still covering the type, not the person's own request), the
     * owning service applies the delegator's authority, and the audit records the person acting on behalf of them.
     *
     * @param  callable(User): string  $act
     */
    private function onBehalf(ApprovalItem $item, User $user, callable $act): string
    {
        $delegations = app(TaskDelegations::class);
        $delegation = $delegations->runningFor($user, (int) $item->delegationId, $item->type);
        if ($delegation === null || (int) $delegation->delegator_id !== (int) $item->onBehalfOfUserId || $delegations->isOwnRequest($user, $item->subjectEmployeeId, $item->record)) {
            throw new AuthorizationException('This item is no longer delegated to you.');
        }
        $message = $delegations->actFor($delegation, $user, fn (User $delegator) => $act($delegator));
        $this->center->forget(User::query()->findOrFail($delegation->delegator_id));

        return $message.' (on behalf of '.$item->onBehalfOfName.')';
    }

    /** HROne parity: decisions on items listed by a tagged ApprovalSource go back to that source. */
    private function extension(ApprovalItem $item, User $user, string $decision, ?string $note): string
    {
        foreach (ApprovalCenter::sources() as $source) {
            if (in_array($item->type, $source->types(), true)) {
                return $source->decide($item, $user, $decision, $note);
            }
        }

        throw new AuthorizationException('Unsupported item.');
    }

    private function apply(ApprovalItem $item, User $user, string $decision, ?string $note): string
    {
        $record = $item->record;

        return match ($item->type) {
            'workflow_task' => tap($item->label($decision).' · '.$item->title, fn () => app(WorkflowEngine::class)->completeTask($record, match ($decision) {
                'approve' => 'approved', 'reject' => 'rejected', default => 'completed',
            }, $note, $user)),
            'leave' => tap($decision === 'approve' ? 'Leave approved' : 'Leave rejected', fn () => $decision === 'approve'
                ? app(Leaves::class)->approve($record, $note, $user)
                : app(Leaves::class)->reject($record, (string) $note, $user)),
            'leave_cancellation' => tap($decision === 'approve' ? 'Cancellation approved' : 'Leave kept', fn () => $decision === 'approve'
                ? app(Leaves::class)->approveCancellation($record, $note, $user)
                : app(Leaves::class)->rejectCancellation($record, (string) $note, $user)),
            'regularisation' => tap($decision === 'approve' ? 'Approved and attendance reprocessed' : 'Correction rejected', fn () => $decision === 'approve'
                ? app(Regularisations::class)->approve($record, $note, $user)
                : app(Regularisations::class)->reject($record, (string) $note, $user)),
            'compensation' => tap($item->label($decision), fn () => match ($decision) {
                'approve' => $record->status === 'submitted'
                    ? app(CompensationChanges::class)->review($record, $user, $note)
                    : app(CompensationChanges::class)->approve($record, $user, $note),
                'reject' => app(CompensationChanges::class)->reject($record, $user, (string) $note),
                'request_change' => app(CompensationChanges::class)->returnToDraft($record, $user, (string) $note),
            }),
            'letter' => tap($decision === 'approve' ? 'Letter approved' : 'Letter rejected', fn () => $decision === 'approve'
                ? app(Letters::class)->approve($record, $user, $note)
                : app(Letters::class)->reject($record, $user, (string) $note)),
            default => $this->extension($item, $user, $decision, $note),
        };
    }
}
