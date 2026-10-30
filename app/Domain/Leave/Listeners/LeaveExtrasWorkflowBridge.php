<?php

namespace App\Domain\Leave\Listeners;

use App\Domain\Identity\Models\User;
use App\Domain\Leave\Models\LeaveDonation;
use App\Domain\Leave\Models\LeaveEncashment;
use App\Domain\Leave\Services\LeaveAdjustments;
use App\Domain\Leave\Services\LeaveDonations;
use App\Domain\Workflow\Events\WorkflowCompleted;
use App\Domain\Workflow\Models\WorkflowTask;
use RuntimeException;

/**
 * HP-1: a workflow on a leave donation or an encashment decides it when it ends, in the name of the person who
 * completed its last task (nobody: the request stays pending for the Approval Center).
 */
final class LeaveExtrasWorkflowBridge
{
    public function handle(WorkflowCompleted $event): void
    {
        $instance = $event->instance;
        $subject = $instance->subject;

        if ((! $subject instanceof LeaveDonation && ! $subject instanceof LeaveEncashment) || $subject->status !== 'pending') {
            return;
        }

        $actorId = WorkflowTask::query()->where('workflow_instance_id', $instance->id)->whereNotNull('completed_by')->orderByDesc('completed_at')->orderByDesc('id')->value('completed_by');
        $actor = $actorId ? User::query()->find($actorId) : null;
        if ($actor === null) {
            return;
        }
        $note = 'Workflow "'.$instance->workflow->name.'" run #'.$instance->id;

        try {
            if ($subject instanceof LeaveDonation) {
                match ($instance->outcome) {
                    'approved' => app(LeaveDonations::class)->approve($subject, $note, $actor),
                    'rejected' => app(LeaveDonations::class)->reject($subject, $note, $actor),
                    default => null,
                };

                return;
            }

            $previous = auth()->user();
            auth()->setUser($actor);
            try {
                match ($instance->outcome) {
                    'approved' => app(LeaveAdjustments::class)->approveEncashment($subject, $note),
                    'rejected' => app(LeaveAdjustments::class)->rejectEncashment($subject, $note),
                    default => null,
                };
            } finally {
                $previous ? auth()->setUser($previous) : auth()->forgetUser();
            }
        } catch (RuntimeException) {
            // Already decided by a person meanwhile; the workflow log keeps the record.
        }
    }
}
