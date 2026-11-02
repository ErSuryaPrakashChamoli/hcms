<?php

namespace App\Domain\Employment\Services;

use App\Domain\Employment\Actions\ChangeManagerAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Notifications\Services\NotificationContext;
use App\Domain\Workflow\Enums\TaskStatus;
use App\Domain\Workflow\Exceptions\WorkflowException;
use App\Domain\Workflow\Models\WorkflowAction;
use App\Domain\Workflow\Models\WorkflowTask;
use App\Domain\Workflow\Services\WorkflowEngine;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * HROne parity WO-16 (matrix 23 §23.9 #4–5, §23.6.3 #13): move several people to a new manager at once.
 *
 * Glue around the one-person change, never a second rule book: each person is moved by ChangeManagerAction::change
 * (employee.position, both people in the actor's scope, a dated reporting line — the current one is closed the day before,
 * a new one opens, audited MANAGER_CHANGED and on the timeline). On top of it, refused with a reason:
 * - the person as their own manager; a loop (the new manager reports to the person, directly or through others, on the
 *   line or on the relationship being changed); a new manager who has left, has not joined yet or is suspended; a person
 *   who has left; anyone outside the actor's access scope; someone who already reports to that manager;
 * - any change that would rewrite history: a reporting line of that type already recorded from a later date, a current
 *   line that already has an end date, or one that started on the effective date itself (closing it would leave an
 *   inverted row). Past rows are never changed except for closing the line that is open on the effective date.
 *
 * Open approvals (§23.9 #5, off unless asked for): with `reassign_tasks`, the moved person's pending workflow steps that
 * wait on the OLD manager because a workflow step resolved the person's manager (approver type `manager`,
 * `hierarchy_level` level 1 or `initiator_manager` for the line; `functional_manager` for the functional line) move to the
 * new manager through WorkflowEngine::reassign (task.reassign, audited ASSIGN with the reason, the run log and the
 * assignee notification). Steps the old manager holds for any other reason — a role, a named user, a context field, a
 * skip-level step, someone else's request, or a step already reassigned or escalated to them — are never touched.
 * Only for a change effective today or earlier (a future-dated change leaves approvals with today's manager).
 */
final class BulkManagerChanges
{
    /** Relationship types whose manager-resolved approval steps can move with the change. */
    public const TASK_TYPES = ['line', 'functional'];

    public function __construct(
        private readonly ChangeManagerAction $action,
        private readonly AccessScopes $scopes,
        private readonly WorkflowEngine $workflows,
        private readonly NotificationContext $context,
    ) {}

    /**
     * Checks the run's inputs once (the bulk operation's prepare()).
     *
     * @param  array<string, mixed>  $data
     * @return array{manager_id: int, type: string, effective_from: string, reason: string, reassign_tasks: bool}
     */
    public function prepare(array $data, User $actor): array
    {
        if (! $actor->hasPermission('employee.position')) {
            throw new RuntimeException('Changing managers needs the employee.position permission.');
        }
        $manager = Employee::query()->find((int) ($data['manager_id'] ?? 0));
        if ($manager === null) {
            throw new RuntimeException('Choose the new manager.');
        }
        if (! $this->scopes->allows($actor, $manager)) {
            throw new RuntimeException('The new manager is outside your access scope.');
        }
        if (($refusal = $this->managerRefusal($manager)) !== null) {
            throw new RuntimeException($refusal);
        }
        $type = (string) ($data['type'] ?? 'line');
        if (! array_key_exists($type, (array) config('peopleos.people.reporting_types', []))) {
            throw new RuntimeException('Choose the relationship that changes.');
        }
        try {
            $from = Carbon::parse((string) ($data['effective_from'] ?? now()->toDateString()))->startOfDay();
        } catch (Throwable) {
            throw new RuntimeException('Give the effective date as a date.');
        }
        $reason = trim(strip_tags((string) ($data['reason'] ?? '')));
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new RuntimeException('Give the reason for the change (at most 500 characters).');
        }
        $reassign = (bool) ($data['reassign_tasks'] ?? false);
        if ($reassign) {
            if (! $actor->hasPermission('task.reassign')) {
                throw new RuntimeException('Moving open approvals needs the task.reassign permission.');
            }
            if (! in_array($type, self::TASK_TYPES, true)) {
                throw new RuntimeException('Open approvals move only with a line or functional manager change.');
            }
            if ($from->greaterThan(today())) {
                throw new RuntimeException('Open approvals move only with a change effective today or earlier; until a future change takes effect they stay with today\'s manager.');
            }
            if ($this->activeUser($manager) === null) {
                throw new RuntimeException('The new manager has no active sign-in, so open approvals cannot move to them.');
            }
        }

        return ['manager_id' => (int) $manager->getKey(), 'type' => $type, 'effective_from' => $from->toDateString(), 'reason' => $reason, 'reassign_tasks' => $reassign];
    }

    /** Why someone cannot become a manager (null = can). */
    public function managerRefusal(Employee $manager): ?string
    {
        $label = $manager->auditLabel();

        return match ($manager->lifecycle_state) {
            LifecycleState::Exited, LifecycleState::Alumni => "The new manager {$label} has left the organisation.",
            LifecycleState::PreEmployee, LifecycleState::Preboarding => "The new manager {$label} has not joined yet.",
            LifecycleState::NotJoined => "The new manager {$label} did not join.",
            LifecycleState::Suspended => "The new manager {$label} is suspended.",
            default => null,
        };
    }

    /** Why $employee cannot move to $manager through $type from $from (null = can). */
    public function refusal(Employee $employee, Employee $manager, string $type, CarbonInterface $from, User $actor): ?string
    {
        $from = Carbon::parse($from)->startOfDay();
        if (! $actor->hasPermission('employee.position')) {
            return 'You cannot change managers.';
        }
        if (! $this->scopes->allows($actor, $employee)) {
            return 'Outside your access scope.';
        }
        if (! $this->scopes->allows($actor, $manager)) {
            return 'The new manager is outside your access scope.';
        }
        if ($employee->is($manager)) {
            return 'An employee cannot be their own manager.';
        }
        if (in_array($employee->lifecycle_state, [LifecycleState::Exited, LifecycleState::Alumni], true)) {
            return 'Has left the organisation.';
        }
        if ($employee->lifecycle_state === LifecycleState::NotJoined) {
            return 'Did not join.';
        }
        if (($refusal = $this->managerRefusal($manager)) !== null) {
            return $refusal;
        }
        if ($this->reportsTo($manager, $employee, $type, $from)) {
            return "{$manager->auditLabel()} reports to this employee (directly or through others); that would create a loop.";
        }
        $current = $this->current($employee, $type, $from);
        if ($current !== null && (int) $current->manager_id === (int) $manager->getKey()) {
            return 'Already reports to '.$manager->auditLabel().'.';
        }
        $later = $this->lines($employee, $type)->where('effective_from', '>', $from->toDateString().' 23:59:59')->orderBy('effective_from')->first();
        if ($later !== null) {
            return 'A later change of this reporting line is already recorded (from '.$later->effective_from->format('j M Y').'); choose a date on or after it — history is never rewritten.';
        }
        if ($current !== null && $current->effective_to !== null) {
            return 'The current reporting line already ends on '.$current->effective_to->format('j M Y').'; history is never rewritten.';
        }
        if ($current !== null && $current->effective_from !== null && $current->effective_from->isSameDay($from)) {
            return 'The current manager was recorded from that same day; choose a later date — history is never rewritten.';
        }

        return null;
    }

    /**
     * Move one person (the bulk operation's handle()); returns the outcome line.
     *
     * @param  array{manager_id: int, type: string, effective_from: string, reason: string, reassign_tasks?: bool}  $input
     */
    public function change(Employee $employee, array $input, User $actor): string
    {
        $manager = Employee::query()->find((int) $input['manager_id']) ?? throw new RuntimeException('The new manager is no longer in your access.');
        $type = (string) $input['type'];
        $from = Carbon::parse($input['effective_from'])->startOfDay();
        if (($refusal = $this->refusal($employee, $manager, $type, $from, $actor)) !== null) {
            throw new RuntimeException($refusal);
        }
        $previousManagerId = $this->current($employee, $type, $from)?->manager_id;
        $reason = (string) $input['reason'];

        try {
            $this->action->change($employee, $manager, $actor, $type, $from, $reason);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException($e->getMessage());
        }
        $line = 'Now reports to '.$manager->auditLabel().' from '.$from->format('j M Y');
        if (! ($input['reassign_tasks'] ?? false)) {
            return $line;
        }
        $previous = $previousManagerId ? Employee::query()->withoutGlobalScope(AccessScope::class)->find($previousManagerId) : null;
        if ($previous === null) {
            return $line.'; no earlier manager, so no open approvals to move';
        }
        [$moved, $left] = $this->moveOpenApprovals($employee, $previous, $manager, $type, $actor, $reason);

        return $line.'; '.$moved.' open approval(s) moved to the new manager'.($left > 0 ? ", {$left} could not move and stay with the previous manager" : '');
    }

    /**
     * The moved person's pending steps that wait on $oldManager as the person's manager (see the class note).
     *
     * @return Collection<int, WorkflowTask>
     */
    public function managerTasks(Employee $employee, Employee $oldManager, string $type): Collection
    {
        if (! in_array($type, self::TASK_TYPES, true) || $oldManager->user_id === null) {
            return collect();
        }
        $kinds = $type === 'functional' ? ['functional_manager'] : ['manager', 'hierarchy_level', 'initiator_manager'];
        $tasks = WorkflowTask::query()->where('status', TaskStatus::Pending)->where('assignee_id', $oldManager->user_id)->whereNull('assignee_role_id')
            ->with(['instance.version', 'instance.subject'])->orderBy('id')->get();
        if ($tasks->isEmpty()) {
            return $tasks;
        }
        // A step reassigned or escalated to the old manager is held for that reason, not as the person's manager.
        $handedOver = WorkflowAction::query()->whereIn('workflow_instance_id', $tasks->pluck('workflow_instance_id')->unique()->all())
            ->whereIn('action', ['task.reassigned', 'task.escalated'])->get(['payload'])
            ->map(fn (WorkflowAction $a) => (int) ($a->payload['task_id'] ?? 0))->filter()->flip();

        return $tasks->filter(function (WorkflowTask $task) use ($employee, $kinds, $handedOver): bool {
            $instance = $task->instance;
            if ($instance === null || isset($handedOver[(int) $task->id])) {
                return false;
            }
            $spec = $this->specOf($task);
            $kind = $spec['type'] ?? null;
            if (! in_array($kind, $kinds, true)) {
                return false;
            }
            if ($kind === 'hierarchy_level' && max(1, (int) ($spec['level'] ?? 1)) !== 1) {
                return false;
            }
            if ($kind === 'initiator_manager') {
                return $employee->user_id !== null && (int) $instance->started_by === (int) $employee->user_id;
            }

            return (int) ($this->context->employeeOf($instance->subject)?->getKey() ?? 0) === (int) $employee->getKey();
        })->values();
    }

    /** @return array{0: int, 1: int} moved, left */
    private function moveOpenApprovals(Employee $employee, Employee $oldManager, Employee $newManager, string $type, User $actor, string $reason): array
    {
        $tasks = $this->managerTasks($employee, $oldManager, $type);
        $user = $this->activeUser($newManager);
        if ($tasks->isEmpty()) {
            return [0, 0];
        }
        if ($user === null) {
            return [0, $tasks->count()];
        }
        $moved = 0;
        foreach ($tasks as $task) {
            try {
                $this->workflows->reassign($task, $user, null, 'Manager change: '.$reason, $actor);
                $moved++;
            } catch (WorkflowException) {
                // e.g. the new manager started that request or it is about them: it stays where it is.
            }
        }

        return [$moved, $tasks->count() - $moved];
    }

    /** @return array<string, mixed> the approver / assignee spec that created this step */
    private function specOf(WorkflowTask $task): array
    {
        $node = $task->instance?->version?->node((string) $task->node_id) ?? [];
        $config = (array) ($node['config'] ?? []);
        if ($task->type === 'task') {
            return (array) ($config['assignee'] ?? []);
        }
        if (($config['mode'] ?? 'single') === 'single') {
            return (array) ($config['approver'] ?? []);
        }

        return (array) (array_values((array) ($config['approvers'] ?? []))[max(0, (int) $task->sequence - 1)] ?? []);
    }

    private function current(Employee $employee, string $type, CarbonInterface $on): ?ReportingRelationship
    {
        return $this->lines($employee, $type)->effectiveOn($on)->orderByDesc('effective_from')->orderByDesc('id')->first();
    }

    /** @return Builder<ReportingRelationship> */
    private function lines(Employee $employee, string $type)
    {
        return ReportingRelationship::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->getKey())->where('type', $type);
    }

    /** Does $start report to $target, directly or through others, on the line or on $type, on $on? */
    private function reportsTo(Employee $start, Employee $target, string $type, CarbonInterface $on): bool
    {
        foreach (array_unique(['line', $type]) as $chain) {
            $seen = [];
            $current = (int) $start->getKey();
            for ($i = 0; $i < 200 && $current > 0 && ! isset($seen[$current]); $i++) {
                $seen[$current] = true;
                $next = ReportingRelationship::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $current)
                    ->when($chain === 'line', fn ($q) => $q->where('is_primary', true), fn ($q) => $q->where('type', $chain))
                    ->effectiveOn($on)->orderByDesc('effective_from')->orderByDesc('id')->value('manager_id');
                if ($next === null) {
                    break;
                }
                if ((int) $next === (int) $target->getKey()) {
                    return true;
                }
                $current = (int) $next;
            }
        }

        return false;
    }

    private function activeUser(Employee $employee): ?User
    {
        $user = $employee->user_id ? User::query()->forCurrentTenant()->find($employee->user_id) : null;

        return $user?->isActive() ? $user : null;
    }
}
