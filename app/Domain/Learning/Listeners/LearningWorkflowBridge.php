<?php

namespace App\Domain\Learning\Listeners;

use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Learning\Services\Learning;
use App\Domain\Workflow\Events\WorkflowCompleted;
use RuntimeException;

/** When an approval workflow on a learning request ends, its outcome decides the request (pinned workflow version). */
final class LearningWorkflowBridge
{
    public function __construct(private readonly Learning $learning) {}

    public function handle(WorkflowCompleted $event): void
    {
        $instance = $event->instance;
        $subject = $instance->subject;

        if (! $subject instanceof LearningEnrolment || ! in_array($subject->status, ['requested', 'pending_approval'], true)) {
            return;
        }

        $note = 'Workflow "'.$instance->workflow->name.'" run #'.$instance->id.($instance->contextValue('last_note') ? ': '.$instance->contextValue('last_note') : '');

        try {
            match ($instance->outcome) {
                'approved' => $this->learning->decide($subject, true, $note, null, true),
                'rejected' => $this->learning->decide($subject, false, $note, null, true),
                default => null,
            };
        } catch (RuntimeException) {
            // Already decided meanwhile; the workflow log keeps the record.
        }
    }
}
