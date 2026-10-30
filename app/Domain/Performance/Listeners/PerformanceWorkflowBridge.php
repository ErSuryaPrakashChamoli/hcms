<?php

namespace App\Domain\Performance\Listeners;

use App\Domain\Identity\Models\User;
use App\Domain\Performance\Models\GoalPlan;
use App\Domain\Performance\Models\ImprovementPlan;
use App\Domain\Performance\Services\GoalPlans;
use App\Domain\Performance\Services\ImprovementPlans;
use App\Domain\Workflow\Events\WorkflowCompleted;
use App\Domain\Workflow\Models\WorkflowTask;
use RuntimeException;

/**
 * HROne setup parity HP-8d / HP-8f: when a workflow on a goal plan (performance.goal_plan.submitted) or an improvement
 * plan (performance.pip.requested / performance.pip.evaluation_requested) ends, its outcome decides the record in the
 * name of the person who completed its last task. With nobody to decide in, or a decision the record's rules refuse,
 * the record stays waiting and goes back to the Approval Center.
 */
final class PerformanceWorkflowBridge
{
    public function handle(WorkflowCompleted $event): void
    {
        $instance = $event->instance;
        $subject = $instance->subject;
        if (! $subject instanceof GoalPlan && ! $subject instanceof ImprovementPlan) {
            return;
        }
        $actorId = WorkflowTask::query()->where('workflow_instance_id', $instance->id)->whereNotNull('completed_by')->orderByDesc('completed_at')->orderByDesc('id')->value('completed_by');
        $actor = $actorId ? User::query()->find($actorId) : null;
        if ($actor === null || ! in_array($instance->outcome, ['approved', 'rejected'], true)) {
            return;
        }
        $note = 'Workflow "'.$instance->workflow->name.'" run #'.$instance->id.($instance->contextValue('last_note') ? ': '.$instance->contextValue('last_note') : '');
        $approved = $instance->outcome === 'approved';

        try {
            if ($subject instanceof GoalPlan) {
                $approved ? app(GoalPlans::class)->approve($subject, $note, $actor) : app(GoalPlans::class)->reject($subject, $note, $actor);

                return;
            }
            $plans = app(ImprovementPlans::class);
            match ($subject->status) {
                'pending_approval' => $approved ? $plans->approve($subject, $note, $actor) : $plans->reject($subject, $note, $actor),
                'pending_outcome' => $approved ? $plans->approveEvaluation($subject, $note, $actor) : $plans->rejectEvaluation($subject, $note, $actor),
                default => null,
            };
        } catch (RuntimeException) {
            // Decided by a person meanwhile, or refused by its rules: the workflow log keeps the record.
        }
    }
}
