<?php

namespace App\Domain\Workflow\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Workflow\Enums\TaskStatus;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Domain\Workflow\Models\WorkflowTask;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * HROne parity RQ-8 (Inbox → IGNORE; permission tree "Ignore api"): system-generated tasks a person may close as not
 * relevant, and bring back.
 *
 * A system task is a pending workflow to-do — never an approval — of a run nobody started by hand: one an event trigger
 * started (`trigger` in the run context, not "manual") or the system itself (no initiator). Marking it not relevant needs
 * `task.ignore` on top of being able to act on the task (`task.act` and its assignee or one of the assignee role's
 * holders); a reason (at most 255 characters) is required. The run moves on as when the to-do is done; the task keeps the
 * reason and who closed it, the run's log and the audit trail record it (TASK_NOT_RELEVANT), and the same people may
 * restore it as a pending to-do (TASK_RESTORED). Nothing is deleted.
 */
final class SystemTasks
{
    public const REASON_MAX = 255;

    /** How far back the "Not relevant" list reaches. */
    public const LIST_DAYS = 90;

    public function __construct(private readonly WorkflowEngine $engine) {}

    /** A to-do of a run started by an event trigger or by the system (never an approval, never a hand-started run). */
    public static function isSystem(WorkflowTask $task): bool
    {
        if ($task->type === 'approval') {
            return false;
        }
        $run = $task->relationLoaded('instance') ? $task->instance : $task->instance()->first();
        if (! $run instanceof WorkflowInstance) {
            return false;
        }
        $trigger = $run->contextValue('trigger');

        return $run->started_by === null || (is_string($trigger) && $trigger !== '' && $trigger !== 'manual');
    }

    /** Whether the person may mark this task not relevant now. */
    public function mayIgnore(User $user, WorkflowTask $task): bool
    {
        return $user->hasPermission('task.ignore') && $task->status === TaskStatus::Pending && self::isSystem($task) && $user->can('act', $task);
    }

    /**
     * Close the task as not relevant, with the reason. Checked again under a lock (the task may have been done meanwhile).
     *
     * @throws AuthorizationException when the person may not
     * @throws InvalidArgumentException when the reason is missing or too long
     */
    public function ignore(User $user, WorkflowTask $task, string $reason): WorkflowInstance
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Say why this task is not relevant.');
        }
        if (mb_strlen($reason) > self::REASON_MAX) {
            throw new InvalidArgumentException('Keep the reason within '.self::REASON_MAX.' characters.');
        }

        return DB::transaction(function () use ($user, $task, $reason) {
            $fresh = WorkflowTask::query()->with('instance')->whereKey($task->getKey())->lockForUpdate()->first();
            if ($fresh === null || ! $this->mayIgnore($user, $fresh)) {
                throw new AuthorizationException('This task cannot be marked not relevant by you (it may already be handled).');
            }

            return $this->engine->markNotRelevant($fresh, $reason, $user);
        });
    }

    /** Whether the person may bring a task marked not relevant back: task.ignore and it was theirs (or their role's). */
    public function mayRestore(User $user, WorkflowTask $task): bool
    {
        if (! $user->hasPermission('task.ignore') || $task->status !== TaskStatus::Skipped || $task->decision !== 'not_relevant') {
            return false;
        }

        return (int) $task->assignee_id === (int) $user->id || (int) $task->completed_by === (int) $user->id
            || ($task->assignee_role_id !== null && $user->roles()->where('roles.id', $task->assignee_role_id)->exists());
    }

    /** @throws AuthorizationException when the person may not restore it */
    public function restore(User $user, WorkflowTask $task, ?string $note = null): WorkflowTask
    {
        if (! $this->mayRestore($user, $task)) {
            throw new AuthorizationException('This task cannot be restored by you.');
        }
        if ($note !== null && mb_strlen(trim($note)) > self::REASON_MAX) {
            throw new InvalidArgumentException('Keep the note within '.self::REASON_MAX.' characters.');
        }

        return $this->engine->restoreNotRelevant($task, $note, $user);
    }

    /**
     * Tasks of the person (or of one of their roles) marked not relevant in the last LIST_DAYS days, newest first.
     *
     * @return Collection<int, WorkflowTask>
     */
    public function notRelevantFor(User $user): Collection
    {
        return WorkflowTask::query()->with(['instance.workflow', 'completer'])->where('status', TaskStatus::Skipped)->where('decision', 'not_relevant')
            ->where(fn (Builder $q) => $q->where(fn (Builder $mine) => $mine->actionableBy($user))->orWhere('completed_by', $user->id))
            ->where('completed_at', '>=', now()->subDays(self::LIST_DAYS))->latest('completed_at')->limit(100)->get();
    }
}
