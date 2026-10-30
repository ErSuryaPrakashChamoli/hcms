<?php

namespace App\Domain\Compliance\Listeners;

use App\Domain\Compliance\Models\EmployeeTaxDeclaration;
use App\Domain\Compliance\Services\InvestmentDeclarations;
use App\Domain\Workflow\Events\WorkflowCompleted;
use App\Domain\Workflow\Support\RequestWorkflows;
use RuntimeException;

/**
 * HROne setup parity P5 (Workflow → Confirmed / proposed investment): a workflow on a submitted investment declaration
 * verifies it, in the name of the person who completed its last task, or sends it back to the employee as a draft. Proof
 * items are still verified one by one on the proofs screen.
 */
final class TaxDeclarationWorkflowBridge
{
    public function handle(WorkflowCompleted $event): void
    {
        $instance = $event->instance;
        $subject = $instance->subject;
        if (! $subject instanceof EmployeeTaxDeclaration || $subject->status !== 'submitted' || ! RequestWorkflows::decides($instance, $subject)) {
            return;
        }
        $actor = RequestWorkflows::lastCompleter($instance);
        if ($actor === null) {
            return;
        }

        try {
            match ($instance->outcome) {
                'approved' => app(InvestmentDeclarations::class)->verify($subject, $actor, RequestWorkflows::note($instance)),
                'rejected' => app(InvestmentDeclarations::class)->returnToEmployee($subject, $actor, RequestWorkflows::note($instance)),
                default => null,
            };
        } catch (RuntimeException) {
            // Changed or decided meanwhile, or refused by its rules: the workflow log keeps the record.
        }
    }
}
