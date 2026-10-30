<?php

namespace App\Domain\People\Listeners;

use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\People\Models\ProfileChangeRequest;
use App\Domain\People\Services\ProfileChanges;
use App\Domain\Workflow\Events\WorkflowCompleted;
use App\Domain\Workflow\Support\RequestWorkflows;
use RuntimeException;

/**
 * HROne parity MS-3 (matrix 22 §22.2.0 #7, P5): a workflow that decides a self-service profile change — one running on
 * profile changes, or on any record and started by `profile.change_requested` — approves or rejects it in the name of
 * the person who completed its last task. Approving applies the change through ProfileChanges in that person's name, so
 * the actions' own rights apply again: a last approver who may not change employee records (a line manager, say) leaves
 * the request pending for the Approval Center, as every P5 bridge does with a refused step.
 */
final class ProfileChangeWorkflowBridge
{
    public function handle(WorkflowCompleted $event): void
    {
        $instance = $event->instance;
        if (! in_array($instance->subject_type, [ProfileChangeRequest::class, (new ProfileChangeRequest)->getMorphClass()], true)) {
            return;
        }
        // Read past the access scope: whoever completed the step need not reach the employee for the bridge to find it.
        $subject = ProfileChangeRequest::query()->withoutGlobalScope(AccessScope::class)->find($instance->subject_id);
        if (! $subject instanceof ProfileChangeRequest || $subject->status !== 'pending' || ! RequestWorkflows::decides($instance, $subject)) {
            return;
        }
        $actor = RequestWorkflows::lastCompleter($instance);
        if ($actor === null) {
            return;
        }

        try {
            match ($instance->outcome) {
                'approved' => app(ProfileChanges::class)->approve($subject, $actor, RequestWorkflows::note($instance), viaWorkflow: true),
                'rejected' => app(ProfileChanges::class)->reject($subject, $actor, RequestWorkflows::note($instance), viaWorkflow: true),
                default => null,
            };
        } catch (RuntimeException) {
            // Decided meanwhile, or refused by the actions' rules: the request waits in the Approval Center; the run's log keeps the record.
        }
    }
}
