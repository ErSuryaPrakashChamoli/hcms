<?php

namespace App\Domain\Workflow\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Services\EmployeeRuleContext;
use App\Domain\Configuration\Services\RuleEngine;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Integration\Support\PayloadGuard;
use App\Domain\Letters\Models\LetterTemplate;
use App\Domain\Letters\Services\Letters;
use App\Domain\Notifications\Services\AudienceResolver;
use App\Domain\Notifications\Services\NotificationContext;
use App\Domain\Notifications\Services\Notifier;
use App\Domain\Notifications\Services\TemplateRenderer;
use App\Domain\Workflow\Enums\InstanceStatus;
use App\Domain\Workflow\Enums\TaskStatus;
use App\Domain\Workflow\Events\WorkflowCompleted;
use App\Domain\Workflow\Events\WorkflowTaskAssigned;
use App\Domain\Workflow\Exceptions\WorkflowException;
use App\Domain\Workflow\Jobs\SendWebhook;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowAction;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Domain\Workflow\Models\WorkflowTask;
use App\Domain\Workflow\Models\WorkflowVersion;
use App\Domain\Workflow\Support\DelegatedDecision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs workflow definitions (§44). A run walks nodes until it reaches one that waits for a
 * person (approval, task) or for time (wait); tasks and the tick resume it.
 */
final class WorkflowEngine
{
    private const MAX_STEPS = 100;

    public function __construct(
        private readonly ApproverResolver $approvers,
        private readonly RuleEngine $rules,
        private readonly EmployeeRuleContext $employeeContext,
        private readonly NotificationContext $notificationContext,
        private readonly AudienceResolver $audience,
        private readonly TemplateRenderer $renderer,
        private readonly Notifier $notifier,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param  array<string, mixed>  $context */
    public function start(Workflow $workflow, ?Model $subject = null, array $context = [], ?User $initiator = null): WorkflowInstance
    {
        $version = $workflow->published()->first() ?? throw new WorkflowException("Workflow [{$workflow->key}] has no published version.");
        $initiator ??= auth()->user();

        return DB::transaction(function () use ($workflow, $version, $subject, $context, $initiator) {
            $instance = WorkflowInstance::create([
                'workflow_id' => $workflow->id,
                'workflow_version_id' => $version->id,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'subject_label' => $subject && method_exists($subject, 'auditLabel') ? $subject->auditLabel() : null,
                'status' => InstanceStatus::Running,
                'current_node_id' => $version->startNodeId(),
                'context' => $context + ['initiator_user_id' => $initiator?->id],
                'started_by' => $initiator?->id,
                'started_at' => now(),
            ]);

            $this->log($instance, $instance->current_node_id, 'started', ['version' => $version->version]);
            $this->audit->record(AuditAction::Submitted, 'workflow', $instance, metadata: ['workflow' => $workflow->key, 'version' => $version->version], entityLabel: $instance->auditLabel());

            return $this->advance($instance);
        });
    }

    /** Execute nodes from the current position until something has to wait. */
    public function advance(WorkflowInstance $instance): WorkflowInstance
    {
        if (! $instance->status->isOpen()) {
            return $instance;
        }

        $version = $instance->version;
        $steps = 0;

        try {
            while ($instance->current_node_id !== null && $steps++ < self::MAX_STEPS) {
                $node = $version->node($instance->current_node_id) ?? throw new WorkflowException("Node [{$instance->current_node_id}] not found.");
                $next = $this->execute($instance, $version, $node);

                if ($next === null) {
                    return $instance->refresh();
                }

                $instance->forceFill(['current_node_id' => $next, 'status' => InstanceStatus::Running])->save();
            }

            if ($steps >= self::MAX_STEPS) {
                throw new WorkflowException('Workflow exceeded the maximum number of steps (loop?).');
            }
        } catch (Throwable $e) {
            $instance->forceFill(['status' => InstanceStatus::Failed, 'error' => mb_substr($e->getMessage(), 0, 255)])->save();
            $this->log($instance, $instance->current_node_id, 'failed', ['error' => $e->getMessage()]);
            report($e);
        }

        return $instance->refresh();
    }

    /**
     * Returns the id of the next node, or null when the run must wait (or has ended).
     *
     * @param  array<string, mixed>  $node
     */
    private function execute(WorkflowInstance $instance, WorkflowVersion $version, array $node): ?string
    {
        $config = $node['config'] ?? [];

        switch ($node['type']) {
            case 'start':
                return $this->follow($version, $node['id']);

            case 'end':
                $this->complete($instance, $instance->contextValue('outcome', 'completed'));

                return null;

            case 'condition':
                $matched = $this->rules->matches($config['conditions'] ?? [], $this->evaluationContext($instance), $config['match'] ?? 'all');
                $this->log($instance, $node['id'], 'condition.evaluated', ['result' => $matched]);

                return $this->follow($version, $node['id'], $matched ? 'yes' : 'no');

            case 'approval':
                if ($instance->tasks()->where('node_id', $node['id'])->exists()) {
                    // Re-entered after a decision: the outcome was stored by completeTask().
                    $outcome = $instance->contextValue("nodes.{$node['id']}.outcome");

                    if ($outcome === null) {
                        return null;
                    }

                    return $this->follow($version, $node['id'], $outcome);
                }

                $this->openApproval($instance, $node);

                return null;

            case 'task':
                if ($instance->tasks()->where('node_id', $node['id'])->exists()) {
                    return $instance->contextValue("nodes.{$node['id']}.outcome") ? $this->follow($version, $node['id']) : null;
                }

                $this->openTask($instance, $node);

                return null;

            case 'notification':
                $this->notify($instance, $node);

                return $this->follow($version, $node['id']);

            case 'wait':
                $wakeAt = $instance->contextValue("nodes.{$node['id']}.wake_at");

                if ($wakeAt !== null && now()->gte($wakeAt)) {
                    return $this->follow($version, $node['id']);
                }

                if ($wakeAt === null) {
                    $wake = now()->addHours((float) ($config['hours'] ?? 24));
                    $this->mergeContext($instance, ["nodes.{$node['id']}.wake_at" => $wake->toIso8601String()]);
                    $instance->forceFill(['status' => InstanceStatus::Waiting, 'wake_at' => $wake])->save();
                    $this->log($instance, $node['id'], 'waiting', ['until' => $wake->toIso8601String()]);
                }

                return null;

            case 'webhook':
                $vars = $this->templateContext($instance);
                SendWebhook::dispatch(
                    $instance,
                    $node['id'],
                    $this->renderer->render($config['url'], $vars),
                    $config['method'] ?? 'POST',
                    $config['headers'] ?? [],
                    // SaaS.8: cleaned like every payload that leaves PeopleOS (the run context can hold anything a form collected).
                    PayloadGuard::clean(['event' => 'workflow.'.$node['id'], 'instance_id' => $instance->id, 'subject' => Arr::get($vars, 'subject'), 'employee' => Arr::get($vars, 'employee'), 'context' => $instance->context]),
                );
                $this->log($instance, $node['id'], 'webhook.dispatched', ['url' => $config['url']]);

                return $this->follow($version, $node['id']);

            case 'automation':
                $this->applyAutomation($instance, $node);

                return $this->follow($version, $node['id']);

            case 'document':
                $this->generateDocument($instance, $node);

                return $this->follow($version, $node['id']);
        }

        throw new WorkflowException("Unsupported node type [{$node['type']}].");
    }

    /** Decide (approval) or finish (task) a pending task on behalf of a user. */
    public function completeTask(WorkflowTask $task, string $decision, ?string $note = null, ?User $actor = null): WorkflowInstance
    {
        $actor ??= auth()->user();

        if ($task->status !== TaskStatus::Pending) {
            throw new WorkflowException('This task has already been handled.');
        }

        if ($actor !== null && ! $task->isActionableBy($actor) && ! $actor->hasPermission('task.reassign')) {
            throw new WorkflowException('You are not assigned to this task.');
        }

        return $this->decideTask($task, $decision, $note, $actor);
    }

    /**
     * HROne setup parity P5 (turnaround time at the last level): the escalation's `auto_decide` step decides a pending
     * approval nobody decided in time. A system decision: no person is recorded as having decided it.
     */
    public function autoDecide(WorkflowTask $task, string $decision, string $reason): WorkflowInstance
    {
        if ($task->status !== TaskStatus::Pending || $task->type !== 'approval' || ! in_array($decision, ['approved', 'rejected'], true)) {
            throw new WorkflowException('Only a pending approval can be decided automatically.');
        }

        return $this->decideTask($task, $decision, $reason, null, system: true);
    }

    /**
     * HROne parity RQ-8 (Inbox → IGNORE): close a pending to-do (never an approval) as not relevant, with the reason. The
     * run moves on exactly as when the to-do is marked done (the step's outcome is "completed"); the task keeps who closed
     * it and why (status skipped, decision not_relevant), so restoreNotRelevant() can bring it back. Who may do it is
     * decided by the caller (SystemTasks: task.ignore, the assignee, a system-generated task).
     */
    public function markNotRelevant(WorkflowTask $task, string $reason, User $actor): WorkflowInstance
    {
        if ($task->status !== TaskStatus::Pending || $task->type === 'approval') {
            throw new WorkflowException('Only a pending task that is not an approval can be marked not relevant.');
        }
        if (trim($reason) === '') {
            throw new WorkflowException('Say why this task is not relevant.');
        }

        return $this->decideTask($task, 'not_relevant', trim($reason), $actor);
    }

    /**
     * HROne parity RQ-8: bring a task marked not relevant back to its assignee as a pending to-do. The run does not go back
     * (the steps after it have already started); finishing the restored to-do records it as done. Refused once the run was
     * cancelled or failed. Nothing is deleted: the earlier closing stays in the run's log and the audit trail.
     */
    public function restoreNotRelevant(WorkflowTask $task, ?string $note, User $actor): WorkflowTask
    {
        return DB::transaction(function () use ($task, $note, $actor) {
            $task = WorkflowTask::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();
            if ($task->status !== TaskStatus::Skipped || $task->decision !== 'not_relevant') {
                throw new WorkflowException('This task was not marked not relevant.');
            }
            $instance = $task->instance()->firstOrFail();
            if (in_array($instance->status, [InstanceStatus::Cancelled, InstanceStatus::Failed], true)) {
                throw new WorkflowException('The workflow run was '.mb_strtolower($instance->status->getLabel()).': the task cannot come back.');
            }
            $reason = $task->note;
            $task->forceFill(['status' => TaskStatus::Pending, 'decision' => null, 'note' => null, 'completed_by' => null, 'completed_at' => null, 'delegate_id' => null])->save();
            $note = filled($note) ? trim((string) $note) : null;
            $this->log($instance, $task->node_id, 'task.restored', ['task_id' => $task->id, 'note' => $note], $actor);
            $this->audit->record(AuditAction::TaskRestored, 'workflow', $instance,
                changes: [['field' => 'task_status', 'before' => 'not_relevant', 'after' => TaskStatus::Pending->value, 'sensitive' => false]],
                reason: $note, metadata: ['task_id' => $task->id, 'node' => $task->node_id, 'earlier_reason' => $reason], entityLabel: $instance->auditLabel(), actor: $actor);

            return $task;
        });
    }

    private function decideTask(WorkflowTask $task, string $decision, ?string $note, ?User $actor, bool $system = false): WorkflowInstance
    {
        return DB::transaction(function () use ($task, $decision, $note, $actor, $system) {
            $status = match ($decision) {
                'approved' => TaskStatus::Approved,
                'rejected' => TaskStatus::Rejected,
                // HROne parity RQ-8: a to-do closed as not relevant (markNotRelevant).
                'not_relevant' => TaskStatus::Skipped,
                default => TaskStatus::Completed,
            };
            // HROne setup parity P4: a delegate deciding in the assignee's stead (the assignee's authority, recorded as the delegate).
            $delegation = $system ? null : DelegatedDecision::current();

            $task->update([
                'status' => $status,
                'decision' => $decision,
                'note' => $note,
                'completed_by' => $actor?->id,
                'completed_at' => now(),
                'delegate_id' => $delegation['delegate_id'] ?? null,
            ]);

            $instance = $task->instance()->with('version')->firstOrFail();
            $this->log($instance, $task->node_id, $system ? 'task.auto_decided' : "task.{$decision}", ['task_id' => $task->id, 'note' => $note] + ($system ? ['decision' => $decision] : []), $actor);
            $this->audit->record(
                match ($decision) {
                    'approved' => AuditAction::Approved,
                    'rejected' => AuditAction::Rejected,
                    'not_relevant' => AuditAction::TaskNotRelevant,
                    default => AuditAction::Update,
                },
                'workflow',
                $instance,
                reason: $note,
                metadata: ['task_id' => $task->id, 'node' => $task->node_id, 'decision' => $decision] + ($system ? ['system' => true] : []),
                entityLabel: $instance->auditLabel(),
                actor: $actor,
            );

            $node = $instance->version->node($task->node_id);
            $outcome = $node['type'] === 'approval' ? $this->approvalOutcome($instance, $node) : 'completed';

            if ($outcome === null) {
                return $instance->refresh();
            }

            $this->mergeContext($instance, [
                "nodes.{$task->node_id}.outcome" => $outcome,
                // HROne parity RQ-8: a to-do closed as not relevant moves the run on as a done one (conditions read "completed").
                'last_decision' => $decision === 'not_relevant' ? 'completed' : $decision,
                'last_note' => $note,
                'outcome' => $outcome === 'completed' ? $instance->contextValue('outcome', 'completed') : $outcome,
            ] + ($decision === 'not_relevant' ? ["nodes.{$task->node_id}.not_relevant" => true] : []));

            return $this->advance($instance);
        });
    }

    public function cancel(WorkflowInstance $instance, ?string $reason = null): WorkflowInstance
    {
        if (! $instance->status->isOpen() && $instance->status !== InstanceStatus::Returned) {
            throw new WorkflowException('Only running, waiting or sent-back workflows can be cancelled.');
        }

        $instance->tasks()->where('status', TaskStatus::Pending)->update(['status' => TaskStatus::Cancelled]);
        $instance->forceFill(['status' => InstanceStatus::Cancelled, 'outcome' => 'cancelled', 'completed_at' => now()])->save();
        $this->log($instance, $instance->current_node_id, 'cancelled', ['reason' => $reason]);
        $this->audit->record(AuditAction::Cancelled, 'workflow', $instance, reason: $reason, entityLabel: $instance->auditLabel());

        return $instance;
    }

    /**
     * HROne setup parity P3 (Inbox → Send back): the approver returns the run to its initiator instead of deciding. The
     * task is closed as sent back, the step's other open tasks are cancelled, and the run waits as Returned until the
     * initiator resubmits it (resubmit()); it then resumes at the same approval step with a fresh round of approvers.
     */
    public function sendBack(WorkflowTask $task, string $note, ?User $actor = null): WorkflowInstance
    {
        $actor ??= auth()->user();
        if ($task->status !== TaskStatus::Pending || $task->type !== 'approval') {
            throw new WorkflowException('Only a pending approval can be sent back.');
        }
        if ($actor === null || ! $task->isActionableBy($actor)) {
            throw new WorkflowException('You are not assigned to this task.');
        }
        if (trim($note) === '') {
            throw new WorkflowException('Say what the requester should change.');
        }

        return DB::transaction(function () use ($task, $note, $actor) {
            $instance = WorkflowInstance::query()->with('version')->whereKey($task->workflow_instance_id)->lockForUpdate()->firstOrFail();
            if (! $instance->status->isOpen()) {
                throw new WorkflowException('This run is no longer waiting for a decision.');
            }
            if ($instance->started_by === null) {
                throw new WorkflowException('This run was started by the system: there is nobody to send it back to.');
            }
            $task->update(['status' => TaskStatus::SentBack, 'decision' => 'sent_back', 'note' => $note, 'completed_by' => $actor->id, 'completed_at' => now()]);
            $instance->tasks()->where('node_id', $task->node_id)->where('status', TaskStatus::Pending)->update(['status' => TaskStatus::Cancelled]);
            $this->mergeContext($instance, ['returned' => ['node' => $task->node_id, 'task_id' => $task->id, 'by' => $actor->id, 'at' => now()->toIso8601String()], 'last_note' => $note]);
            $instance->forceFill(['status' => InstanceStatus::Returned])->save();
            $this->log($instance, $task->node_id, 'task.sent_back', ['task_id' => $task->id, 'note' => $note], $actor);
            $this->audit->record(AuditAction::StatusChange, 'workflow', $instance, changes: [['field' => 'status', 'before' => 'running', 'after' => InstanceStatus::Returned->value, 'sensitive' => false]],
                reason: $note, metadata: ['task_id' => $task->id, 'node' => $task->node_id, 'decision' => 'sent_back'], entityLabel: $instance->auditLabel(), actor: $actor);

            return $instance->refresh();
        });
    }

    /** HROne setup parity P3: the initiator answers a run sent back to them; it resumes at the step that sent it back. */
    public function resubmit(WorkflowInstance $instance, ?string $note = null, ?User $actor = null): WorkflowInstance
    {
        $actor ??= auth()->user();
        if ($instance->status !== InstanceStatus::Returned) {
            throw new WorkflowException('Only a run sent back to its initiator can be resubmitted.');
        }
        if ($actor === null || (int) $instance->started_by !== (int) $actor->id) {
            throw new WorkflowException('Only the person who started this run resubmits it.');
        }

        return DB::transaction(function () use ($instance, $note, $actor) {
            $instance = WorkflowInstance::query()->with('version')->whereKey($instance->id)->lockForUpdate()->firstOrFail();
            if ($instance->status !== InstanceStatus::Returned) {
                throw new WorkflowException('This run is no longer sent back.');
            }
            $nodeId = (string) ($instance->contextValue('returned.node') ?? $instance->current_node_id);
            $node = $instance->version->node($nodeId) ?? throw new WorkflowException("Node [{$nodeId}] not found.");
            // A fresh round: the step's earlier tasks (the sent-back one, approvals before it) no longer count.
            $since = (int) $instance->tasks()->where('node_id', $nodeId)->max('id');
            $this->mergeContext($instance, ["nodes.{$nodeId}.since" => $since, 'returned' => null, 'last_response' => $note]);
            $instance->forceFill(['status' => InstanceStatus::Running, 'current_node_id' => $nodeId])->save();
            $this->log($instance, $nodeId, 'resubmitted', ['note' => $note], $actor);
            $this->audit->record(AuditAction::StatusChange, 'workflow', $instance, changes: [['field' => 'status', 'before' => InstanceStatus::Returned->value, 'after' => InstanceStatus::Running->value, 'sensitive' => false]],
                reason: $note, metadata: ['node' => $nodeId, 'decision' => 'resubmitted'], entityLabel: $instance->auditLabel(), actor: $actor);
            $this->openApproval($instance, $node);

            return $instance->refresh();
        });
    }

    /**
     * HROne setup parity P3 (Reassign): a holder of task.reassign moves a pending task to one person or one role. Nobody
     * receives a decision on a request they started or that is about them.
     */
    public function reassign(WorkflowTask $task, ?User $user, ?Role $role, string $reason, ?User $actor = null): WorkflowTask
    {
        $actor ??= auth()->user();
        if ($actor === null || ! $actor->hasPermission('task.reassign')) {
            throw new WorkflowException('Reassigning tasks needs the task.reassign permission.');
        }
        if ($task->status !== TaskStatus::Pending) {
            throw new WorkflowException('This task has already been handled.');
        }
        if (($user === null) === ($role === null)) {
            throw new WorkflowException('Choose one person or one role.');
        }
        if (trim($reason) === '') {
            throw new WorkflowException('Give the reason for reassigning.');
        }
        if ($user !== null && (! $user->isActive() || (int) $user->tenant_id !== (int) $task->tenant_id)) {
            throw new WorkflowException('Choose an active person of this organisation.');
        }
        $instance = $task->instance()->first();
        $subjectUser = $this->notificationContext->employeeOf($instance?->subject)?->user_id;
        if ($user !== null && in_array((int) $user->id, array_filter([(int) $instance?->started_by, (int) $subjectUser]), true)) {
            throw new WorkflowException('Nobody decides a request they started or that is about them.');
        }

        return DB::transaction(function () use ($task, $user, $role, $reason, $actor, $instance) {
            $from = $task->assigneeLabel();
            $task->forceFill(['assignee_id' => $user?->id, 'assignee_role_id' => $role?->id])->save();
            $task->unsetRelation('assignee')->unsetRelation('assigneeRole');
            $to = $task->assigneeLabel();
            if ($instance !== null) {
                $this->log($instance, $task->node_id, 'task.reassigned', ['task_id' => $task->id, 'from' => $from, 'to' => $to, 'reason' => $reason], $actor);
                $this->audit->record(AuditAction::Assign, 'workflow', $instance, changes: [['field' => 'assignee', 'before' => $from, 'after' => $to, 'sensitive' => false]],
                    reason: $reason, metadata: ['task_id' => $task->id, 'node' => $task->node_id], entityLabel: $instance->auditLabel(), actor: $actor);
            }
            WorkflowTaskAssigned::dispatch($task, 'reassigned');

            return $task;
        });
    }

    /** Scheduler entry point: resume waits that are due. */
    public function tick(): int
    {
        $resumed = 0;

        WorkflowInstance::query()
            ->where('status', InstanceStatus::Waiting)
            ->where('wake_at', '<=', now())
            ->orderBy('id')
            ->each(function (WorkflowInstance $instance) use (&$resumed) {
                $this->advance($instance);
                $resumed++;
            });

        return $resumed;
    }

    /** Flat variables for conditions: employee dimensions, instance context, subject attributes. */
    public function evaluationContext(WorkflowInstance $instance): array
    {
        $subject = $instance->subject;
        $employee = $this->notificationContext->employeeOf($subject);
        $context = $employee ? $this->employeeContext->build($employee) : [];

        if ($subject !== null) {
            foreach ($subject->getAttributes() as $key => $value) {
                $context["subject.{$key}"] = $subject->getAttribute($key) instanceof \BackedEnum ? $subject->getAttribute($key)->value : $subject->getAttribute($key);
            }

            if (isset($subject->data) && is_array($subject->data)) {
                foreach (Arr::dot($subject->data) as $key => $value) {
                    $context["data.{$key}"] = $value;
                }
            }
        }

        foreach (Arr::dot($instance->context ?? []) as $key => $value) {
            $context[$key] = $value;
        }

        return $context;
    }

    private function templateContext(WorkflowInstance $instance): array
    {
        return $this->notificationContext->build($instance->subject, ['context' => $instance->context, 'workflow' => ['name' => $instance->workflow->name, 'run' => $instance->id]], $instance->starter);
    }

    private function openApproval(WorkflowInstance $instance, array $node): void
    {
        $config = $node['config'] ?? [];
        $mode = $config['mode'] ?? 'single';
        $specs = $mode === 'single' ? [$config['approver'] ?? []] : ($config['approvers'] ?? []);

        if ($specs === [] || ($specs === [[]])) {
            throw new WorkflowException("Approval node [{$node['id']}] has no approver.");
        }

        $toOpen = $mode === 'sequential' ? [array_shift($specs)] : $specs;
        $sequence = 1;

        foreach ($toOpen as $spec) {
            $this->createTask($instance, $node, 'approval', $spec, $sequence++);
        }
    }

    private function openTask(WorkflowInstance $instance, array $node): void
    {
        $this->createTask($instance, $node, 'task', $node['config']['assignee'] ?? [], 1);
    }

    private function createTask(WorkflowInstance $instance, array $node, string $type, array $spec, int $sequence): WorkflowTask
    {
        ['user' => $user, 'role' => $role] = $this->approvers->resolve($spec, $instance);

        if ($user === null && $role === null) {
            $fallback = $node['config']['fallback'] ?? null;

            if ($fallback) {
                ['user' => $user, 'role' => $role] = $this->approvers->resolve($fallback, $instance);
            }
        }
        // HROne setup parity: a manager approver with no manager goes to the manager reliever, when one is set.
        if ($user === null && $role === null) {
            $user = $this->approvers->reliever($spec);
        }

        if ($user === null && $role === null) {
            throw new WorkflowException("Could not find an assignee for node [{$node['id']}] (".($spec['type'] ?? 'no type').').');
        }

        $sla = (float) ($node['config']['sla_hours'] ?? config('peopleos.workflows.default_sla_hours'));
        $vars = $this->templateContext($instance);

        $task = WorkflowTask::create([
            'workflow_instance_id' => $instance->id,
            'node_id' => $node['id'],
            'type' => $type,
            'title' => $this->renderer->render($node['config']['title'] ?? $node['name'] ?? ucfirst($type), $vars),
            'instructions' => isset($node['config']['instructions']) ? $this->renderer->render($node['config']['instructions'], $vars) : null,
            'assignee_id' => $user?->id,
            'assignee_role_id' => $role?->id,
            'sequence' => $sequence,
            'due_at' => now()->addHours($sla),
        ]);

        $this->log($instance, $node['id'], 'task.created', ['task_id' => $task->id, 'assignee' => $task->assigneeLabel()]);
        WorkflowTaskAssigned::dispatch($task);

        return $task;
    }

    /** Aggregate decisions for an approval node; null while more decisions are needed. */
    private function approvalOutcome(WorkflowInstance $instance, array $node): ?string
    {
        $config = $node['config'] ?? [];
        $mode = $config['mode'] ?? 'single';
        // HROne setup parity P3: after a send-back and resubmission only the step's new round counts.
        $tasks = $instance->tasks()->where('node_id', $node['id'])->where('id', '>', (int) $instance->contextValue("nodes.{$node['id']}.since", 0))->get();
        $approved = $tasks->where('status', TaskStatus::Approved)->count();
        $rejected = $tasks->where('status', TaskStatus::Rejected)->count();
        $pending = $tasks->where('status', TaskStatus::Pending)->count();

        switch ($mode) {
            case 'single':
                return $rejected > 0 ? 'rejected' : ($approved > 0 ? 'approved' : null);

            case 'sequential':
                if ($rejected > 0) {
                    return 'rejected';
                }

                $specs = $config['approvers'] ?? [];

                if ($approved < count($specs)) {
                    $this->createTask($instance, $node, 'approval', $specs[$approved], $approved + 1);

                    return null;
                }

                return 'approved';

            case 'parallel':
                if ($rejected > 0) {
                    $instance->tasks()->where('node_id', $node['id'])->where('status', TaskStatus::Pending)->update(['status' => TaskStatus::Skipped]);

                    return 'rejected';
                }

                return $pending === 0 ? 'approved' : null;

            case 'majority':
                $total = $tasks->count();

                if ($approved * 2 > $total) {
                    $instance->tasks()->where('node_id', $node['id'])->where('status', TaskStatus::Pending)->update(['status' => TaskStatus::Skipped]);

                    return 'approved';
                }

                if ($rejected * 2 >= $total) {
                    $instance->tasks()->where('node_id', $node['id'])->where('status', TaskStatus::Pending)->update(['status' => TaskStatus::Skipped]);

                    return 'rejected';
                }

                return null;
        }

        throw new WorkflowException("Unknown approval mode [{$mode}].");
    }

    private function notify(WorkflowInstance $instance, array $node): void
    {
        $config = $node['config'] ?? [];
        $vars = $this->templateContext($instance);
        $users = $this->audience->resolve($config['audience'] ?? [], $vars);

        $deliveries = $this->notifier->send(
            $users,
            $config['channels'] ?? ['in_app'],
            $this->renderer->render($config['subject'] ?? $node['name'] ?? 'Workflow update', $vars),
            $this->renderer->render($config['body'] ?? '', $vars),
            'workflow.notification',
            $instance,
        );

        $this->log($instance, $node['id'], 'notification.sent', ['recipients' => $users->pluck('id')->all(), 'deliveries' => $deliveries->count()]);
    }

    private function applyAutomation(WorkflowInstance $instance, array $node): void
    {
        $subject = $instance->subject;
        $set = $node['config']['set'] ?? [];

        if ($subject === null || $set === []) {
            $this->log($instance, $node['id'], 'skipped', ['reason' => 'No subject or nothing to set.']);

            return;
        }

        $vars = $this->templateContext($instance);
        $values = [];

        foreach ($set as $field => $value) {
            $values[$field] = is_string($value) ? $this->renderer->render($value, $vars) : $value;
        }

        if (method_exists($subject, 'withAuditReason')) {
            $subject->withAuditReason("Workflow: {$instance->workflow->name} (run #{$instance->id})");
        }

        $subject->update($values);
        $this->log($instance, $node['id'], 'automation.applied', ['set' => $values]);
    }

    private function complete(WorkflowInstance $instance, string $outcome): void
    {
        $instance->forceFill(['status' => InstanceStatus::Completed, 'outcome' => $outcome, 'completed_at' => now(), 'current_node_id' => null])->save();
        $this->log($instance, null, 'completed', ['outcome' => $outcome]);
        WorkflowCompleted::dispatch($instance);
    }

    private function follow(WorkflowVersion $version, string $nodeId, ?string $label = null): string
    {
        $edges = $version->edgesFrom($nodeId);

        foreach ($edges as $edge) {
            if ($label !== null && ($edge['label'] ?? null) === $label) {
                return $edge['to'];
            }
        }

        foreach ($edges as $edge) {
            if (empty($edge['label'])) {
                return $edge['to'];
            }
        }

        if ($label === 'rejected' || $label === 'completed') {
            // Rejections with no explicit path end the run.
            foreach ($version->nodes() as $node) {
                if ($node['type'] === 'end') {
                    return $node['id'];
                }
            }
        }

        throw new WorkflowException("No edge leaves node [{$nodeId}]".($label ? " for [{$label}]" : '').'.');
    }

    private function mergeContext(WorkflowInstance $instance, array $values): void
    {
        $context = $instance->context ?? [];

        foreach ($values as $key => $value) {
            Arr::set($context, $key, $value);
        }

        $instance->forceFill(['context' => $context])->save();
    }

    private function log(WorkflowInstance $instance, ?string $nodeId, string $action, array $payload = [], ?User $actor = null): void
    {
        $actorId = $actor?->id ?? auth()->id();
        // HROne setup parity P4: what a delegate does in a delegator's stead is logged as the delegate's, on behalf of the delegator.
        if (($delegation = DelegatedDecision::current()) !== null && ($actorId === null || (int) $actorId === (int) $delegation['delegator_id'])) {
            $actorId = $delegation['delegate_id'];
            $payload['on_behalf_of'] = ['user_id' => $delegation['delegator_id'], 'name' => $delegation['delegator_name'], 'delegation_id' => $delegation['delegation_id']];
        }

        WorkflowAction::create([
            'workflow_instance_id' => $instance->id,
            'node_id' => $nodeId,
            'action' => $action,
            'actor_id' => $actorId,
            'payload' => $payload === [] ? null : $payload,
            'created_at' => now(),
        ]);
    }

    /**
     * HROne setup parity P5: the `document` node writes a letter from a template for the run's employee (Letters::generate,
     * numbered and approved as the template says; `issue` = issue it at once when no approval is needed) and keeps its id
     * in the run context (`letter_id`, `nodes.<id>.letter_id`). A node without a template is skipped, as before.
     */
    private function generateDocument(WorkflowInstance $instance, array $node): void
    {
        $config = $node['config'] ?? [];
        if (empty($config['letter_template_id'])) {
            $this->log($instance, $node['id'], 'skipped', ['reason' => 'No letter template chosen for this step.']);

            return;
        }
        $template = LetterTemplate::query()->whereKey((int) $config['letter_template_id'])->where('status', 'active')->first()
            ?? throw new WorkflowException("Document node [{$node['id']}]: the letter template is missing or inactive.");
        $employee = $this->notificationContext->employeeOf($instance->subject)
            ?? throw new WorkflowException("Document node [{$node['id']}]: the run is not about an employee.");
        $source = $instance->subject instanceof Model && ! $instance->subject instanceof Employee ? $instance->subject : $instance;
        $letters = app(Letters::class);
        $letter = $letters->generate($template, $employee, ['workflow' => ['name' => $instance->workflow->name, 'run' => $instance->id]], $instance->starter, $source);
        if (($config['issue'] ?? 'draft') === 'issue' && $letter->status === 'approved') {
            $letter = $letters->issue($letter, $instance->starter);
        }
        $this->mergeContext($instance, ["nodes.{$node['id']}.letter_id" => $letter->id, 'letter_id' => $letter->id]);
        $this->log($instance, $node['id'], 'document.generated', ['letter_id' => $letter->id, 'number' => $letter->number, 'status' => $letter->status]);
    }
}
