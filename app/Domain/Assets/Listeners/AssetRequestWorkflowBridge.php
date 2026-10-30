<?php

namespace App\Domain\Assets\Listeners;

use App\Domain\Assets\Models\AssetRequest;
use App\Domain\Assets\Services\AssetRequests;
use App\Domain\Identity\Models\User;
use App\Domain\Workflow\Events\WorkflowCompleted;
use App\Domain\Workflow\Models\WorkflowTask;
use RuntimeException;

/**
 * HROne setup parity AS-B: when a workflow on an asset request (asset.requested / asset.return_requested) ends, its
 * outcome approves or rejects the request in the name of the person who completed its last task. With nobody to decide
 * in, or a request decided meanwhile, it is left as it is (the Approval Center lists it again).
 */
final class AssetRequestWorkflowBridge
{
    public function handle(WorkflowCompleted $event): void
    {
        $instance = $event->instance;
        $subject = $instance->subject;
        if (! $subject instanceof AssetRequest || $subject->status !== 'requested') {
            return;
        }
        $actorId = WorkflowTask::query()->where('workflow_instance_id', $instance->id)->whereNotNull('completed_by')->orderByDesc('completed_at')->orderByDesc('id')->value('completed_by');
        $actor = $actorId ? User::query()->find($actorId) : null;
        if ($actor === null) {
            return;
        }
        $note = 'Workflow "'.$instance->workflow->name.'" run #'.$instance->id.($instance->contextValue('last_note') ? ': '.$instance->contextValue('last_note') : '');

        try {
            match ($instance->outcome) {
                'approved' => app(AssetRequests::class)->approve($subject, $note, $actor),
                'rejected' => app(AssetRequests::class)->reject($subject, $note, $actor),
                default => null,
            };
        } catch (RuntimeException) {
            // Refused by its rules (e.g. the approver may not decide it): the request stays for the Approval Center.
        }
    }
}
