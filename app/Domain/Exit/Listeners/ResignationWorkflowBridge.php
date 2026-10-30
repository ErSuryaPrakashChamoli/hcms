<?php

namespace App\Domain\Exit\Listeners;

use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Services\Exits;
use App\Domain\Workflow\Events\WorkflowCompleted;
use App\Domain\Workflow\Support\RequestWorkflows;
use RuntimeException;

/**
 * HROne setup parity P5 (Workflow → Resignation): a workflow on an employee's resignation ("Resignation submitted", or
 * set to run on exit cases) accepts it, in the name of the person who completed its last task, or — not accepted —
 * withdraws it (the employee goes back to active). The notice period and clearances run as before meanwhile.
 */
final class ResignationWorkflowBridge
{
    public function handle(WorkflowCompleted $event): void
    {
        $instance = $event->instance;
        $subject = $instance->subject;
        if (! $subject instanceof ExitCase || $subject->type !== 'resignation' || ! $subject->isOpen() || $subject->resignation_accepted_at !== null
            || ! RequestWorkflows::decides($instance, $subject)) {
            return;
        }
        $actor = RequestWorkflows::lastCompleter($instance);
        if ($actor === null) {
            return;
        }

        try {
            match ($instance->outcome) {
                'approved' => app(Exits::class)->acceptResignation($subject, $actor, RequestWorkflows::note($instance)),
                'rejected' => app(Exits::class)->withdraw($subject, 'Resignation not accepted. '.RequestWorkflows::note($instance), $actor),
                default => null,
            };
        } catch (RuntimeException) {
            // Withdrawn or completed meanwhile: the workflow log keeps the record.
        }
    }
}
