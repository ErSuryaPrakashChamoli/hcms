<?php

namespace App\Domain\Expense\Listeners;

use App\Domain\Expense\Models\ExpenseAdvance;
use App\Domain\Expense\Models\ExpenseReportPart;
use App\Domain\Expense\Models\TravelRequest;
use App\Domain\Expense\Models\TravelRequestPart;
use App\Domain\Expense\Services\ExpenseAdvances;
use App\Domain\Expense\Services\ExpenseReports;
use App\Domain\Expense\Services\TravelRequests;
use App\Domain\Identity\Models\User;
use App\Domain\Workflow\Events\WorkflowCompleted;
use App\Domain\Workflow\Models\WorkflowTask;
use RuntimeException;

/**
 * HROne setup parity EX-C/EX-D: when a workflow on an expense report part (expense.report_submitted /
 * expense.exceptional_submitted) or an expense advance (expense.advance_requested / expense.advance_cancel_requested)
 * ends, its outcome decides the record in the name of the person who completed its last task — an approved part is
 * approved as claimed (line amounts are changed on the report itself, before the workflow ends). With nobody to decide
 * in, the record stays pending and goes back to the Approval Center. EX-F: travel requests (expense.travel_requested) and
 * their cancellations (expense.travel_cancel_requested) the same way; owner decision 2026-10-29: the part of a trip
 * spanning several workflow groups (approved or rejected on its own; TravelRequests::approvePart / rejectPart).
 */
final class ExpenseWorkflowBridge
{
    public function handle(WorkflowCompleted $event): void
    {
        $instance = $event->instance;
        $subject = $instance->subject;
        if (! $subject instanceof ExpenseReportPart && ! $subject instanceof ExpenseAdvance && ! $subject instanceof TravelRequest && ! $subject instanceof TravelRequestPart) {
            return;
        }
        $actorId = WorkflowTask::query()->where('workflow_instance_id', $instance->id)->whereNotNull('completed_by')->orderByDesc('completed_at')->orderByDesc('id')->value('completed_by');
        $actor = $actorId ? User::query()->find($actorId) : null;
        if ($actor === null) {
            return;
        }
        $note = 'Workflow "'.$instance->workflow->name.'" run #'.$instance->id.($instance->contextValue('last_note') ? ': '.$instance->contextValue('last_note') : '');

        try {
            if ($subject instanceof TravelRequestPart) {
                match (true) {
                    $instance->outcome === 'approved' && $subject->status === 'pending' => app(TravelRequests::class)->approvePart($subject, $note, $actor),
                    $instance->outcome === 'rejected' && $subject->status === 'pending' => app(TravelRequests::class)->rejectPart($subject, $note, $actor),
                    default => null,
                };

                return;
            }
            if ($subject instanceof TravelRequest) {
                match (true) {
                    $instance->outcome === 'approved' && $subject->status === 'submitted' => app(TravelRequests::class)->approve($subject, $note, $actor),
                    $instance->outcome === 'rejected' && $subject->status === 'submitted' => app(TravelRequests::class)->reject($subject, $note, $actor),
                    $instance->outcome === 'approved' && $subject->status === 'cancel_requested' => app(TravelRequests::class)->approveCancellation($subject, $note, $actor),
                    $instance->outcome === 'rejected' && $subject->status === 'cancel_requested' => app(TravelRequests::class)->rejectCancellation($subject, $note, $actor),
                    default => null,
                };

                return;
            }
            if ($subject instanceof ExpenseReportPart) {
                match (true) {
                    $instance->outcome === 'approved' && $subject->status === 'pending' => app(ExpenseReports::class)->approvePart($subject, null, $note, $actor),
                    $instance->outcome === 'rejected' && $subject->status === 'pending' => app(ExpenseReports::class)->rejectPart($subject, $note, $actor),
                    default => null,
                };

                return;
            }
            match (true) {
                $instance->outcome === 'approved' && $subject->status === 'requested' => app(ExpenseAdvances::class)->approve($subject, null, $note, $actor),
                $instance->outcome === 'rejected' && $subject->status === 'requested' => app(ExpenseAdvances::class)->reject($subject, $note, $actor),
                $instance->outcome === 'approved' && $subject->status === 'cancel_requested' => app(ExpenseAdvances::class)->approveCancellation($subject, $note, $actor),
                $instance->outcome === 'rejected' && $subject->status === 'cancel_requested' => app(ExpenseAdvances::class)->rejectCancellation($subject, $note, $actor),
                default => null,
            };
        } catch (RuntimeException) {
            // Decided by a person meanwhile, or refused by its rules: the workflow log keeps the record.
        }
    }
}
