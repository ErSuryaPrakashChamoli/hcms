<?php

namespace App\Domain\Workforce\Listeners;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Workflow\Events\WorkflowCompleted;
use App\Domain\Workflow\Models\WorkflowTask;
use App\Domain\Workforce\Events\WorkforceEvent;
use App\Domain\Workforce\Models\WorkforcePlanVersion;
use Illuminate\Support\Facades\DB;

/**
 * Phase 10: when a plan approval workflow is configured, its outcome (instance pinned to the
 * published workflow version) decides the submitted plan version. Separation of duties is checked
 * again here: an approval completed by the submitter is not accepted — the version stays under review
 * and the refusal is audited.
 */
final class WorkforceWorkflowBridge
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(WorkflowCompleted $event): void
    {
        $instance = $event->instance;
        $subject = $instance->subject;
        if (! $subject instanceof WorkforcePlanVersion || (int) $subject->workflow_instance_id !== (int) $instance->id || ! in_array($subject->status, ['submitted', 'under_review'], true)) {
            return;
        }
        DB::transaction(function () use ($subject, $instance) {
            $version = WorkforcePlanVersion::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
            $approvers = WorkflowTask::query()->where('workflow_instance_id', $instance->id)->where('decision', 'approved')->pluck('completed_by')->filter();
            if ($instance->outcome === 'approved' && $approvers->contains((int) $version->submitted_by)) {
                $version->update(['status' => $version->status === 'submitted' ? 'under_review' : $version->status, 'workflow_instance_id' => null]);
                $this->audit->record(AuditAction::Rejected, 'workforce', $version, [], 'Workflow approval by the submitter is not accepted (separation of duties)', metadata: ['event' => 'plan_workflow_sod_refused', 'workflow_instance_id' => $instance->id]);

                return;
            }
            if ($instance->outcome === 'approved') {
                $steps = $version->status === 'submitted' ? ['under_review', 'approved'] : ['approved'];
                foreach ($steps as $status) {
                    $version->update(['status' => $status, ...($status === 'approved' ? ['approved_by' => $approvers->last(), 'approved_at' => now(), 'workflow_instance_id' => null] : [])]);
                }
                $this->audit->record(AuditAction::Approved, 'workforce', $version, [['field' => 'status', 'before' => 'submitted', 'after' => 'approved']], 'Workflow run #'.$instance->id, metadata: ['event' => 'plan_approved', 'workflow_instance_id' => $instance->id]);
                WorkforceEvent::dispatch('workforce.plan.approved', null, $version, ['version' => $version->version], array_filter([(int) $version->submitted_by]));

                return;
            }
            $version->update(['status' => 'rejected', 'workflow_instance_id' => null, 'decision_note' => 'Rejected in workflow run #'.$instance->id]);
            $this->audit->record(AuditAction::Rejected, 'workforce', $version, [['field' => 'status', 'before' => $subject->status, 'after' => 'rejected']], 'Workflow run #'.$instance->id, metadata: ['event' => 'plan_rejected']);
        });
    }
}
