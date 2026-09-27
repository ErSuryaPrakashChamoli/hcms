<?php

namespace App\Domain\Leave\Listeners;

use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Services\Leaves;
use App\Domain\Workflow\Events\WorkflowCompleted;
use RuntimeException;

/** When a workflow on a leave request ends, its outcome decides the request (§45 approval engine). */
final class LeaveWorkflowBridge
{
    public function __construct(private readonly Leaves $leaves) {}

    public function handle(WorkflowCompleted $event): void
    {
        $instance = $event->instance;
        $subject = $instance->subject;

        if (! $subject instanceof LeaveRequest || $subject->status !== 'pending') {
            return;
        }

        $note = 'Workflow "'.$instance->workflow->name.'" run #'.$instance->id.($instance->contextValue('last_note') ? ': '.$instance->contextValue('last_note') : '');

        try {
            match ($instance->outcome) {
                'approved' => $this->leaves->approve($subject, $note, null),
                'rejected' => $this->leaves->reject($subject, $note, null),
                default => null,
            };
        } catch (RuntimeException) {
            // Already decided by a person meanwhile; the workflow log keeps the record.
        }
    }
}
