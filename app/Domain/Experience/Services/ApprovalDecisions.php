<?php

namespace App\Domain\Experience\Services;

use App\Domain\Attendance\Services\Regularisations;
use App\Domain\Compensation\Services\CompensationChanges;
use App\Domain\Experience\Support\ApprovalItem;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Services\Leaves;
use App\Domain\Letters\Services\Letters;
use App\Domain\Workflow\Services\WorkflowEngine;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

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
        $item = $this->center->find($user, $id);
        if ($item === null || ! $item->can($decision)) {
            $this->metrics->record('approval.failed');

            throw new AuthorizationException('This item is no longer waiting for your decision.');
        }
        $note = filled($note) ? trim((string) $note) : null;
        if ($item->needsNote($decision) && $note === null) {
            throw new InvalidArgumentException('Add a reason so the requester knows what to do next.');
        }

        $message = $this->apply($item, $user, $decision, $note);
        $this->center->forget($user);
        $this->metrics->record('approval.decided');

        return $message;
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
            default => throw new AuthorizationException('Unsupported item.'),
        };
    }
}
