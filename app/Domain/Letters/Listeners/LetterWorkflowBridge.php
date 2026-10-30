<?php

namespace App\Domain\Letters\Listeners;

use App\Domain\Letters\Models\Letter;
use App\Domain\Letters\Services\Letters;
use App\Domain\Workflow\Events\WorkflowCompleted;
use App\Domain\Workflow\Support\RequestWorkflows;
use RuntimeException;

/**
 * HROne setup parity P5 (Letter templates → Approver): a workflow set to run on letters (on "Letter requested", raised
 * when a template needs approval) approves or rejects the letter in the name of the person who completed its last task;
 * whoever requested the letter still never approves it. Workflows on that trigger that run on any record decide
 * nothing, as before.
 */
final class LetterWorkflowBridge
{
    public function handle(WorkflowCompleted $event): void
    {
        $instance = $event->instance;
        $subject = $instance->subject;
        if (! $subject instanceof Letter || $subject->status !== 'pending_approval' || ! RequestWorkflows::decides($instance, $subject)) {
            return;
        }
        $actor = RequestWorkflows::lastCompleter($instance);
        if ($actor === null) {
            return;
        }

        try {
            match ($instance->outcome) {
                'approved' => app(Letters::class)->approve($subject, $actor, RequestWorkflows::note($instance)),
                'rejected' => app(Letters::class)->reject($subject, $actor, RequestWorkflows::note($instance)),
                default => null,
            };
        } catch (RuntimeException) {
            // Decided by a person meanwhile, or refused by its rules: the workflow log keeps the record.
        }
    }
}
