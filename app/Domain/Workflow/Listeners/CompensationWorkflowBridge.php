<?php

namespace App\Domain\Workflow\Listeners;

use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Services\CompensationChanges;
use App\Domain\Workflow\Events\WorkflowCompleted;
use App\Domain\Workflow\Support\RequestWorkflows;
use RuntimeException;

/**
 * HROne setup parity P5 (Workflow → Salary structure): a workflow on a compensation change moves it one step in the name
 * of the person who completed its last task — a submitted change is reviewed, a reviewed change approved — or rejects
 * it. CompensationChanges applies its own rules again (review and approval permissions, scope, and separation of
 * duties: the proposer, reviewer and approver are three people), so a workflow never weakens them; a step it cannot
 * take is left for the Approval Center. Start one workflow on "Compensation change submitted" and, for the approval,
 * another on "Compensation change reviewed". It lives with the workflow engine: Compensation itself listens to no other
 * module (CompensationInvariantsTest 15); this only takes the steps a person took in the workflow.
 */
final class CompensationWorkflowBridge
{
    public function handle(WorkflowCompleted $event): void
    {
        $instance = $event->instance;
        $subject = $instance->subject;
        if (! $subject instanceof CompensationChange || ! in_array($subject->status, CompensationChange::PENDING, true) || ! RequestWorkflows::decides($instance, $subject)) {
            return;
        }
        $actor = RequestWorkflows::lastCompleter($instance);
        if ($actor === null) {
            return;
        }
        $note = RequestWorkflows::note($instance);
        $changes = app(CompensationChanges::class);

        try {
            match (true) {
                $instance->outcome === 'approved' && $subject->status === 'submitted' => $changes->review($subject, $actor, $note),
                $instance->outcome === 'approved' && $subject->status === 'under_review' => $changes->approve($subject, $actor, $note),
                $instance->outcome === 'rejected' => $changes->reject($subject, $actor, $note),
                default => null,
            };
        } catch (RuntimeException) {
            // Refused by its rules (permissions, scope, separation of duties) or decided meanwhile: left for the Approval Center.
        }
    }
}
