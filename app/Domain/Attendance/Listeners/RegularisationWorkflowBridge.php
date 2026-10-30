<?php

namespace App\Domain\Attendance\Listeners;

use App\Domain\Attendance\Models\AttendanceRegularisation;
use App\Domain\Attendance\Services\Regularisations;
use App\Domain\Workflow\Events\WorkflowCompleted;
use App\Domain\Workflow\Support\RequestWorkflows;
use RuntimeException;

/**
 * HROne setup parity P5 (Workflow → Regularization): a workflow set to run on attendance regularisations (on
 * "Attendance regularisation requested") approves or rejects the request in the name of the person who completed its
 * last task. Earlier workflows on that trigger that run on any record decide nothing, as before. With nobody to decide
 * in, or a request decided meanwhile, it stays as it is (the Approval Center lists it again).
 */
final class RegularisationWorkflowBridge
{
    public function handle(WorkflowCompleted $event): void
    {
        $instance = $event->instance;
        $subject = $instance->subject;
        if (! $subject instanceof AttendanceRegularisation || $subject->status !== 'pending' || ! RequestWorkflows::decides($instance, $subject)) {
            return;
        }
        $actor = RequestWorkflows::lastCompleter($instance);
        if ($actor === null) {
            return;
        }

        try {
            match ($instance->outcome) {
                'approved' => app(Regularisations::class)->approve($subject, RequestWorkflows::note($instance), $actor),
                'rejected' => app(Regularisations::class)->reject($subject, RequestWorkflows::note($instance), $actor),
                default => null,
            };
        } catch (RuntimeException) {
            // Decided by a person meanwhile, or refused by its rules: the workflow log keeps the record.
        }
    }
}
