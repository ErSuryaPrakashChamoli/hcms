<?php

namespace App\Domain\Onboarding\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Models\FormSubmission;
use App\Domain\Configuration\Services\EmployeeRuleContext;
use App\Domain\Configuration\Services\RuleEngine;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Lifecycle\Services\Timeline;
use App\Domain\Notifications\Services\NotificationContext;
use App\Domain\Notifications\Services\NotificationEngine;
use App\Domain\Onboarding\Events\OnboardingCompleted;
use App\Domain\Onboarding\Events\OnboardingTaskAssigned;
use App\Domain\Onboarding\Models\OnboardingPlan;
use App\Domain\Onboarding\Models\OnboardingTask;
use App\Domain\Onboarding\Models\OnboardingTemplate;
use App\Domain\Onboarding\Models\OnboardingTemplateItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Template selection, plan generation, task completion and progress (§21). */
final class Onboarding
{
    public function __construct(
        private readonly RuleEngine $rules,
        private readonly EmployeeRuleContext $context,
        private readonly AuditRecorder $audit,
        private readonly Timeline $timeline,
        private readonly NotificationEngine $notifications,
        private readonly NotificationContext $notificationContext,
    ) {}

    /** The best matching active template by priority, or null. */
    public function templateFor(Employee $employee): ?OnboardingTemplate
    {
        $context = $this->context->build($employee);

        return OnboardingTemplate::query()
            ->where('status', 'active')
            ->orderBy('priority')->orderBy('id')
            ->get()
            ->first(fn (OnboardingTemplate $t) => $this->rules->matches($t->conditions ?? [], $context, 'all'));
    }

    public function start(Employee $employee, ?OnboardingTemplate $template = null, ?string $reason = null): OnboardingPlan
    {
        if ($employee->onboardingPlan()->where('status', 'in_progress')->exists()) {
            throw new RuntimeException('This employee already has an onboarding plan in progress.');
        }

        $template ??= $this->templateFor($employee) ?? throw new RuntimeException('No onboarding template matches this employee.');
        $anchor = $employee->joining_date ?? $employee->expected_joining_date ?? now();

        return DB::transaction(function () use ($employee, $template, $anchor, $reason) {
            $plan = new OnboardingPlan([
                'employee_id' => $employee->id,
                'onboarding_template_id' => $template->id,
                'anchor_date' => $anchor,
                'started_by' => auth()->id(),
                'started_at' => now(),
            ]);
            $plan->withAuditReason($reason)->save();

            foreach ($template->items()->get() as $item) {
                $this->createTask($plan, $employee, $item);
            }

            $this->timeline->record($employee, 'onboarding', "Onboarding started ({$template->name})", now(), $reason, $plan);
            $this->notifications->fire('onboarding.started', $this->notificationContext->build($employee, ['onboarding' => ['template' => $template->name, 'plan' => $plan->id]]), $plan);

            return $plan->refresh();
        });
    }

    public function complete(OnboardingTask $task, ?string $note = null, ?Model $evidence = null, ?User $actor = null): OnboardingTask
    {
        $actor ??= auth()->user();
        $task->loadMissing('plan.employee');

        if ($task->status !== 'pending') {
            throw new RuntimeException('This task is already closed.');
        }

        if ($actor && ! $task->isActionableBy($actor) && ! $actor->hasPermission('onboarding.manage')) {
            throw new RuntimeException('This task is not assigned to you.');
        }

        $task->update([
            'status' => 'completed',
            'note' => $note,
            'completed_by' => $actor?->id,
            'completed_at' => now(),
            'employee_document_id' => $evidence instanceof EmployeeDocument ? $evidence->id : $task->employee_document_id,
            'form_submission_id' => $evidence instanceof FormSubmission ? $evidence->id : $task->form_submission_id,
        ]);

        $this->audit->record(AuditAction::Update, 'onboarding', $task->plan, changes: [['field' => $task->title, 'before' => 'pending', 'after' => 'completed']], reason: $note, metadata: ['task_id' => $task->id], actor: $actor);
        $this->recompute($task->plan);

        return $task;
    }

    public function skip(OnboardingTask $task, string $note, ?User $actor = null): OnboardingTask
    {
        $actor ??= auth()->user();
        $task->loadMissing('plan.employee');

        if ($task->status !== 'pending') {
            throw new RuntimeException('This task is already closed.');
        }

        $task->update(['status' => 'skipped', 'note' => $note, 'completed_by' => $actor?->id, 'completed_at' => now()]);
        $this->audit->record(AuditAction::Update, 'onboarding', $task->plan, changes: [['field' => $task->title, 'before' => 'pending', 'after' => 'skipped']], reason: $note, metadata: ['task_id' => $task->id], actor: $actor);
        $this->recompute($task->plan);

        return $task;
    }

    public function cancel(OnboardingPlan $plan, ?string $reason = null): OnboardingPlan
    {
        $plan->tasks()->where('status', 'pending')->update(['status' => 'skipped', 'note' => $reason]);
        $plan->withAuditReason($reason)->update(['status' => 'cancelled', 'completed_at' => now()]);

        return $plan;
    }

    public function recompute(OnboardingPlan $plan): OnboardingPlan
    {
        $plan->loadMissing('employee');
        $tasks = $plan->tasks()->get();
        $total = $tasks->count();
        $closed = $tasks->whereIn('status', ['completed', 'skipped'])->count();
        $progress = $total === 0 ? 100 : (int) floor($closed * 100 / $total);
        $mandatoryOpen = $tasks->where('is_mandatory', true)->where('status', 'pending')->count();

        $plan->forceFill(['progress' => $progress])->saveQuietly();

        if ($plan->status === 'in_progress' && $mandatoryOpen === 0 && $tasks->where('status', 'pending')->count() === 0) {
            $plan->withAuditReason('All tasks closed')->update(['status' => 'completed', 'completed_at' => now()]);
            $this->timeline->record($plan->employee, 'onboarding', 'Onboarding completed', now(), null, $plan);
            $this->notifications->fire('onboarding.completed', $this->notificationContext->build($plan->employee, ['onboarding' => ['plan' => $plan->id]]), $plan);
            OnboardingCompleted::dispatch($plan);
        }

        return $plan;
    }

    private function createTask(OnboardingPlan $plan, Employee $employee, OnboardingTemplateItem $item): OnboardingTask
    {
        [$userId, $roleId] = $this->owner($employee, $item);
        $offset = $item->due_offset_days ?? config("peopleos.onboarding.phases.{$item->phase}.offset", 0);

        $task = OnboardingTask::create([
            'onboarding_plan_id' => $plan->id,
            'onboarding_template_item_id' => $item->id,
            'phase' => $item->phase,
            'type' => $item->type,
            'title' => $item->title,
            'description' => $item->description,
            'owner_user_id' => $userId,
            'owner_role_id' => $roleId,
            'document_type_id' => $item->document_type_id,
            'form_id' => $item->form_id,
            'due_on' => $plan->anchor_date->copy()->addDays((int) $offset),
            'is_mandatory' => $item->is_mandatory,
            'sort_order' => $item->sort_order,
        ]);

        OnboardingTaskAssigned::dispatch($task);

        return $task;
    }

    /** @return array{0: ?int, 1: ?int} [user id, role id] */
    private function owner(Employee $employee, OnboardingTemplateItem $item): array
    {
        $employee->loadMissing('currentManager.manager');

        return match ($item->owner_type) {
            'employee' => [$employee->user_id, null],
            'manager' => [$employee->currentManager?->manager?->user_id, null],
            'buddy' => [$employee->reportingRelationships()->where('type', 'buddy')->effectiveOn()->first()?->manager?->user_id, null],
            'hrbp' => [$employee->reportingRelationships()->where('type', 'hrbp')->effectiveOn()->first()?->manager?->user_id, null],
            'role' => [null, $item->owner_role_id],
            'user' => [$item->owner_user_id, null],
            default => [null, null],
        };
    }
}
