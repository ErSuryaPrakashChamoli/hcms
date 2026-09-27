<?php

namespace App\Domain\Workflow\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Workflow\Enums\TaskStatus;
use App\Domain\Workflow\Events\WorkflowTaskAssigned;
use App\Domain\Workflow\Models\WorkflowAction;
use App\Domain\Workflow\Models\WorkflowTask;

/**
 * Escalation (§46): per node, steps [{after_hours, action: remind|escalate_to_manager|escalate_to_role, role_id?}]
 * measured from task creation. Without steps, overdue tasks get a reminder once a day.
 */
final class EscalationEngine
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function run(): int
    {
        $actions = 0;

        WorkflowTask::query()
            ->with(['instance.version', 'assignee', 'assigneeRole'])
            ->where('status', TaskStatus::Pending)
            ->orderBy('id')
            ->each(function (WorkflowTask $task) use (&$actions) {
                $actions += $this->process($task);
            });

        return $actions;
    }

    public function process(WorkflowTask $task): int
    {
        $task->loadMissing(['instance.version', 'assignee', 'assigneeRole']);
        $node = $task->instance?->version?->node($task->node_id) ?? [];
        $steps = array_values($node['config']['escalation'] ?? []);
        $ageHours = $task->created_at->diffInHours(now());
        $done = 0;

        if ($steps === []) {
            if ($task->isOverdue() && ($task->last_reminded_at === null || $task->last_reminded_at->lt(now()->subDay()))) {
                $this->remind($task);
                $done++;
            }

            return $done;
        }

        foreach ($steps as $index => $step) {
            if ($index < $task->escalation_level || $ageHours < (float) ($step['after_hours'] ?? 0)) {
                continue;
            }

            match ($step['action'] ?? 'remind') {
                'escalate_to_manager' => $this->escalateToManager($task),
                'escalate_to_role' => $this->escalateToRole($task, (int) ($step['role_id'] ?? 0)),
                default => $this->remind($task),
            };

            $task->forceFill(['escalation_level' => $index + 1])->save();
            $done++;
        }

        return $done;
    }

    private function remind(WorkflowTask $task): void
    {
        $task->forceFill(['last_reminded_at' => now()])->save();
        $this->log($task, 'task.reminded');
        WorkflowTaskAssigned::dispatch($task, 'reminder');
    }

    private function escalateToManager(WorkflowTask $task): void
    {
        $assignee = $task->assignee;
        $employee = $assignee ? Employee::query()->where('user_id', $assignee->id)->first() : null;
        $manager = $employee?->currentManager?->manager;
        $managerUser = $manager?->user_id ? User::query()->find($manager->user_id) : null;

        if ($managerUser === null) {
            $this->remind($task);

            return;
        }

        $this->reassign($task, $managerUser, null);
    }

    private function escalateToRole(WorkflowTask $task, int $roleId): void
    {
        $role = Role::query()->find($roleId);

        if ($role === null) {
            $this->remind($task);

            return;
        }

        $this->reassign($task, null, $role);
    }

    private function reassign(WorkflowTask $task, ?User $user, ?Role $role): void
    {
        $from = $task->assigneeLabel();
        $task->forceFill(['assignee_id' => $user?->id, 'assignee_role_id' => $role?->id])->save();
        $task->unsetRelation('assignee')->unsetRelation('assigneeRole');

        $this->log($task, 'task.escalated', ['from' => $from, 'to' => $task->assigneeLabel()]);
        $this->audit->record(AuditAction::Escalated, 'workflow', $task->instance, changes: [['field' => 'assignee', 'before' => $from, 'after' => $task->assigneeLabel()]], metadata: ['task_id' => $task->id], entityLabel: $task->instance->auditLabel());
        WorkflowTaskAssigned::dispatch($task, 'escalated');
    }

    private function log(WorkflowTask $task, string $action, array $payload = []): void
    {
        WorkflowAction::create([
            'workflow_instance_id' => $task->workflow_instance_id,
            'node_id' => $task->node_id,
            'action' => $action,
            'payload' => ['task_id' => $task->id] + $payload,
            'created_at' => now(),
        ]);
    }
}
