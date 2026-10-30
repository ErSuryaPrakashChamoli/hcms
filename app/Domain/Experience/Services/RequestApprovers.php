<?php

namespace App\Domain\Experience\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Contracts\DescribesApprovalRoute;
use App\Domain\Experience\Contracts\RequestType;
use App\Domain\Experience\Support\ApprovalItem;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Workflow\Enums\InstanceStatus;
use App\Domain\Workflow\Enums\TaskStatus;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Domain\Workflow\Models\WorkflowTask;
use App\Domain\Workflow\Support\RequestWorkflows;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;

/**
 * HROne parity RQ-2 (request chrome): whom a raised request waits with now — read only, nothing is decided or changed.
 *
 * - A workflow deciding the request is in flight (RequestWorkflows::decidingRuns): the assignees of its pending tasks
 *   (a person, or the active members of a role) and the earliest due time.
 * - Otherwise the module's own approvers: the people who have the request in their Approval Center right now. They are
 *   found by asking the Approval Center itself, signed in as each candidate (ScreenAccess::as, the way delegation gathers
 *   a delegator's queue), so every source's own rule — permission, access scope, "never one's own", a request sent back —
 *   decides. Candidates: the employee's line manager and the holders of the type's approver rights (at most CANDIDATES).
 */
final class RequestApprovers
{
    /** Candidates asked at most (the line manager first). */
    public const CANDIDATES = 15;

    /** Members of an assignee role notified at most. */
    public const ROLE_MEMBERS = 50;

    /** @var array<string, array{via: ?string, users: Collection<int, User>, labels: list<string>, due: ?CarbonInterface}> */
    private array $memo = [];

    /**
     * Via `workflow` or `approval_center` (null: nobody has it), the people, how to name them, the earliest due time.
     *
     * @return array{via: ?string, users: Collection<int, User>, labels: list<string>, due: ?CarbonInterface}
     */
    public function current(RequestType $type, Model $record): array
    {
        $key = $type->key().':'.$record::class.':'.$record->getKey();

        return $this->memo[$key] ??= $this->find($type, $record);
    }

    /** @return array{via: ?string, users: Collection<int, User>, labels: list<string>, due: ?CarbonInterface} */
    private function find(RequestType $type, Model $record): array
    {
        $records = $type instanceof DescribesApprovalRoute ? $type->waitingRecords($record) : [$record];
        $runs = RequestWorkflows::decidingRuns(...$records)->filter(fn (WorkflowInstance $run) => in_array($run->status, InstanceStatus::inFlight(), true));
        if ($runs->isNotEmpty()) {
            $tasks = WorkflowTask::query()->with(['assignee', 'assigneeRole'])->whereIn('workflow_instance_id', $runs->pluck('id'))
                ->where('status', TaskStatus::Pending->value)->orderBy('id')->get();
            $users = collect();
            foreach ($tasks as $task) {
                if ($task->assignee !== null && $task->assignee->isActive()) {
                    $users->push($task->assignee);
                } elseif ($task->assigneeRole !== null) {
                    $users = $users->merge($task->assigneeRole->users()->where('users.status', UserStatus::Active->value)->limit(self::ROLE_MEMBERS)->get());
                }
            }

            return [
                'via' => 'workflow',
                'users' => $users->unique('id')->values(),
                'labels' => $tasks->map(fn (WorkflowTask $t) => $t->assigneeLabel())->unique()->values()->all(),
                'due' => $tasks->pluck('due_at')->filter()->sort()->first(),
            ];
        }

        $employee = $type instanceof DescribesApprovalRoute ? $type->employeeOf($record) : null;
        if ($employee === null) {
            return ['via' => null, 'users' => collect(), 'labels' => [], 'due' => null];
        }
        $approvers = collect();
        $due = null;
        $center = app(ApprovalCenter::class);
        foreach ($this->candidates($type, $employee) as $candidate) {
            try {
                $items = ScreenAccess::as($candidate, fn () => $center->pendingAbout($candidate, (int) $employee->getKey()));
            } catch (Throwable $e) {
                report($e);

                continue;
            }
            $match = $items->first(fn (ApprovalItem $item) => $this->about($item, $records));
            if ($match !== null) {
                $approvers->push($candidate);
                $due = $match->dueAt !== null && ($due === null || $match->dueAt->lt($due)) ? $match->dueAt : $due;
            }
        }

        return [
            'via' => $approvers->isEmpty() ? null : 'approval_center',
            'users' => $approvers->values(),
            'labels' => $approvers->map(fn (User $u) => (string) $u->name)->values()->all(),
            'due' => $due,
        ];
    }

    /** @return Collection<int, User> the line manager first, then holders of the type's approver rights; never the employee */
    private function candidates(RequestType $type, Employee $employee): Collection
    {
        $employee->loadMissing('currentManager.manager');
        $managerUserId = $employee->currentManager?->manager?->user_id;
        $candidates = collect();
        if ($managerUserId) {
            $manager = User::query()->forCurrentTenant()->where('status', UserStatus::Active->value)->find($managerUserId);
            $manager !== null && $candidates->push($manager);
        }
        $permissions = $type instanceof DescribesApprovalRoute ? $type->approverPermissions() : [];
        if ($permissions !== []) {
            $candidates = $candidates->merge(User::query()->forCurrentTenant()->where('status', UserStatus::Active->value)
                ->whereHas('roles', fn ($q) => $q->where('roles.status', 'active')->whereHas('permissions', fn ($p) => $p->whereIn('key', $permissions)))
                ->orderBy('id')->limit(self::CANDIDATES)->get());
        }

        return $candidates->unique('id')->reject(fn (User $u) => (int) $u->getKey() === (int) $employee->user_id)->take(self::CANDIDATES)->values();
    }

    /** @param  list<Model>  $records */
    private function about(ApprovalItem $item, array $records): bool
    {
        $record = $item->record;
        if (! $record instanceof Model) {
            return false;
        }
        foreach ($records as $candidate) {
            if ($record::class === $candidate::class && (string) $record->getKey() === (string) $candidate->getKey()) {
                return true;
            }
        }

        return false;
    }
}
