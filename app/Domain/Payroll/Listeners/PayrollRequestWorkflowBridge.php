<?php

namespace App\Domain\Payroll\Listeners;

use App\Domain\Claims\Models\ReimbursementClaim;
use App\Domain\Claims\Services\ReimbursementClaims;
use App\Domain\Loans\Models\Loan;
use App\Domain\Loans\Services\Loans;
use App\Domain\Payroll\Models\VariablePayRequest;
use App\Domain\Payroll\Services\VariablePay;
use App\Domain\Workflow\Events\WorkflowCompleted;
use App\Domain\Workflow\Support\RequestWorkflows;
use RuntimeException;

/**
 * HROne setup parity P5 (Workflow → Loan, Reimbursement, Variable): a workflow on a loan, reimbursement claim or
 * variable pay request (started by its "… requested" trigger, or set to run on that request type) approves or rejects
 * it in the name of the person who completed its last task, through the owning service (its rules apply again: never
 * one's own request, guarantors must have accepted …). With nobody to decide in, or a request decided meanwhile, it
 * stays as it is and the Approval Center lists it again.
 */
final class PayrollRequestWorkflowBridge
{
    public function handle(WorkflowCompleted $event): void
    {
        $instance = $event->instance;
        $subject = $instance->subject;
        if (! ($subject instanceof Loan || $subject instanceof ReimbursementClaim || $subject instanceof VariablePayRequest)
            || $subject->status !== 'pending' || ! RequestWorkflows::decides($instance, $subject)) {
            return;
        }
        $actor = RequestWorkflows::lastCompleter($instance);
        if ($actor === null || ! in_array($instance->outcome, ['approved', 'rejected'], true)) {
            return;
        }
        $note = RequestWorkflows::note($instance);
        $approve = $instance->outcome === 'approved';

        try {
            match (true) {
                $subject instanceof Loan => $approve ? app(Loans::class)->approve($subject, $note, $actor) : app(Loans::class)->reject($subject, $note, $actor),
                $subject instanceof ReimbursementClaim => $approve ? app(ReimbursementClaims::class)->approve($subject, null, $note, $actor) : app(ReimbursementClaims::class)->reject($subject, $note, $actor),
                default => $approve ? app(VariablePay::class)->approve($subject, $note, $actor) : app(VariablePay::class)->reject($subject, $note, $actor),
            };
        } catch (RuntimeException) {
            // Decided by a person meanwhile, or refused by its rules: the workflow log keeps the record.
        }
    }
}
