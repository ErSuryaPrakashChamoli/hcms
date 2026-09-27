<?php

namespace App\Domain\Workflow\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Services\EmployeeRuleContext;
use App\Domain\Configuration\Services\RuleEngine;
use App\Domain\Identity\Models\User;
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
                    ['event' => 'workflow.'.$node['id'], 'instance_id' => $instance->id, 'subject' => Arr::get($vars, 'subject'), 'employee' => Arr::get($vars, 'employee'), 'context' => $instance->context],
                );
                $this->log($instance, $node['id'], 'webhook.dispatched', ['url' => $config['url']]);

                return $this->follow($version, $node['id']);

            case 'automation':
                $this->applyAutomation($instance, $node);

                return $this->follow($version, $node['id']);

            case 'document':
                $this->log($instance, $node['id'], 'skipped', ['reason' => 'Document generation arrives with the Letter Factory.']);

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

        return DB::transaction(function () use ($task, $decision, $note, $actor) {
            $status = match ($decision) {
                'approved' => TaskStatus::Approved,
                'rejected' => TaskStatus::Rejected,
                default => TaskStatus::Completed,
            };

            $task->update([
                'status' => $status,
                'decision' => $decision,
                'note' => $note,
                'completed_by' => $actor?->id,
                'completed_at' => now(),
            ]);

            $instance = $task->instance()->with('version')->firstOrFail();
            $this->log($instance, $task->node_id, "task.{$decision}", ['task_id' => $task->id, 'note' => $note], $actor);
            $this->audit->record(
                $decision === 'approved' ? AuditAction::Approved : ($decision === 'rejected' ? AuditAction::Rejected : AuditAction::Update),
                'workflow',
                $instance,
                reason: $note,
                metadata: ['task_id' => $task->id, 'node' => $task->node_id, 'decision' => $decision],
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
                'last_decision' => $decision,
                'last_note' => $note,
                'outcome' => $outcome === 'completed' ? $instance->contextValue('outcome', 'completed') : $outcome,
            ]);

            return $this->advance($instance);
        });
    }

    public function cancel(WorkflowInstance $instance, ?string $reason = null): WorkflowInstance
    {
        if (! $instance->status->isOpen()) {
            throw new WorkflowException('Only running or waiting workflows can be cancelled.');
        }

        $instance->tasks()->where('status', TaskStatus::Pending)->update(['status' => TaskStatus::Cancelled]);
        $instance->forceFill(['status' => InstanceStatus::Cancelled, 'outcome' => 'cancelled', 'completed_at' => now()])->save();
        $this->log($instance, $instance->current_node_id, 'cancelled', ['reason' => $reason]);
        $this->audit->record(AuditAction::Cancelled, 'workflow', $instance, reason: $reason, entityLabel: $instance->auditLabel());

        return $instance;
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
        $tasks = $instance->tasks()->where('node_id', $node['id'])->get();
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
        WorkflowAction::create([
            'workflow_instance_id' => $instance->id,
            'node_id' => $nodeId,
            'action' => $action,
            'actor_id' => $actor?->id ?? auth()->id(),
            'payload' => $payload === [] ? null : $payload,
            'created_at' => now(),
        ]);
    }
}
