<?php

namespace App\Domain\Engagement\Listeners;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Communication\Models\Announcement;
use App\Domain\Engagement\Models\EngagementCampaign;
use App\Domain\Engagement\Models\SurveyVersion;
use App\Domain\Engagement\Services\Surveys;
use App\Domain\Workflow\Events\WorkflowCompleted;
use App\Domain\Workflow\Models\WorkflowTask;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Phase 13: when an approval workflow is configured (peopleos.engagement.approval_workflows), its
 * outcome decides the survey version, announcement or campaign under review.
 *
 * Separation of duties is checked again here: an approval completed by the preparer is not accepted.
 * The item stays in review and the refusal is audited. A rejection returns the item to draft with
 * the run noted.
 */
final class EngagementWorkflowBridge
{
    public function __construct(private readonly AuditRecorder $audit, private readonly Surveys $surveys) {}

    public function handle(WorkflowCompleted $event): void
    {
        $instance = $event->instance;
        $subject = $instance->subject;
        if (! ($subject instanceof SurveyVersion || $subject instanceof Announcement || $subject instanceof EngagementCampaign)
            || (int) $subject->workflow_instance_id !== (int) $instance->id || $subject->status !== 'in_review') {
            return;
        }
        [$module, $approvedAction] = match (true) {
            $subject instanceof SurveyVersion => ['engagement', AuditAction::SurveyApproved],
            $subject instanceof Announcement => ['communication', AuditAction::AnnouncementApproved],
            default => ['engagement', AuditAction::CampaignApproved],
        };

        DB::transaction(function () use ($subject, $instance, $module, $approvedAction) {
            /** @var Model $current */
            $current = $subject->newQuery()->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();
            if ($current->status !== 'in_review' || (int) $current->workflow_instance_id !== (int) $instance->id) {
                return;
            }
            $approvers = WorkflowTask::query()->where('workflow_instance_id', $instance->id)->where('decision', 'approved')->pluck('completed_by')->filter();
            if ($instance->outcome === 'approved' && $approvers->contains((int) $current->prepared_by)) {
                $current->update(['workflow_instance_id' => null]);
                $this->audit->record(AuditAction::Rejected, $module, $current, [], 'Workflow approval by the preparer is not accepted (separation of duties)', metadata: ['event' => 'workflow_sod_refused', 'workflow_instance_id' => $instance->id]);

                return;
            }
            if ($instance->outcome === 'approved') {
                $current->update(['status' => 'approved', 'approved_by' => $approvers->last(), 'approved_at' => now(), 'workflow_instance_id' => null, 'lock_version' => $current->lock_version + 1]);
                if ($current instanceof SurveyVersion) {
                    $current->update(['checksum' => $this->surveys->checksum($current)]);
                }
                $this->audit->record($approvedAction, $module, $current, [['field' => 'status', 'before' => 'in_review', 'after' => 'approved']], 'Workflow run #'.$instance->id, metadata: ['workflow_instance_id' => $instance->id]);

                return;
            }
            $current->update(['status' => 'draft', 'workflow_instance_id' => null, 'submitted_at' => null, 'decision_note' => 'Rejected in workflow run #'.$instance->id, 'lock_version' => $current->lock_version + 1]);
            $this->audit->record(AuditAction::Rejected, $module, $current, [['field' => 'status', 'before' => 'in_review', 'after' => 'draft']], 'Workflow run #'.$instance->id);
        });
    }
}
