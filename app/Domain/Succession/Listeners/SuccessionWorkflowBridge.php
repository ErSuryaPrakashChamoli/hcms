<?php

namespace App\Domain\Succession\Listeners;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Succession\Models\SuccessionPlan;
use App\Domain\Talent\Models\TalentReviewSession;
use App\Domain\Talent\Services\TalentReviews;
use App\Domain\Workflow\Events\WorkflowCompleted;

/**
 * Phase 9: approval workflows (pinned to their published version) decide succession plan activation
 * and talent review completion when a workflow key is configured. Rejection leaves the record where
 * it was, with the workflow log as the record of the decision.
 */
final class SuccessionWorkflowBridge
{
    public function __construct(private readonly TalentReviews $reviews, private readonly AuditRecorder $audit) {}

    public function handle(WorkflowCompleted $event): void
    {
        $instance = $event->instance;
        $subject = $instance->subject;
        $approved = $instance->outcome === 'approved';

        if ($subject instanceof SuccessionPlan && $subject->status === 'draft' && (int) $subject->workflow_instance_id === (int) $instance->id) {
            $subject->update($approved ? ['status' => 'active', 'workflow_instance_id' => null] : ['workflow_instance_id' => null]);
            $this->audit->record($approved ? AuditAction::Approved : AuditAction::Rejected, 'succession', $subject, [], 'Workflow run #'.$instance->id, metadata: ['event' => 'succession_plan_workflow']);
        }

        if ($subject instanceof TalentReviewSession && $subject->status === 'in_progress' && (int) $subject->workflow_instance_id === (int) $instance->id) {
            if ($approved) {
                $this->reviews->finish($subject);
            } else {
                $subject->update(['workflow_instance_id' => null]);
            }
        }
    }
}
