<?php

namespace App\Domain\Workflow\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Services\ScreenAccess;
use App\Domain\Experience\Support\InboxActions;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Notifications\Services\NotificationContext;
use App\Domain\Workflow\Enums\TaskStatus;
use App\Domain\Workflow\Events\InboxEvent;
use App\Domain\Workflow\Models\TaskDelegation;
use App\Domain\Workflow\Models\WorkflowTask;
use App\Domain\Workflow\Support\DelegatedDecision;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * HROne setup parity P4 (Inbox → Delegate, "out of office"). A person hands their approvals of some request types to a
 * colleague for a period; while it runs the colleague (the delegate) sees those items in the Approval Center and the
 * task inbox and decides them in the delegator's stead: the owning service checks the delegator's authority (scope,
 * permissions, separation of duties) and the audit records the delegate acting on behalf of the delegator
 * (DelegatedDecision). Off until `inbox.delegation.types` lists request types; nothing is delegated beyond them.
 *
 * Rules: one delegate per person at a time (no overlapping periods); never to oneself or to someone who is out of office
 * then too (delegations do not chain); a delegate never decides a request they raised or that is about them. People
 * set up their own (task.delegate); task.delegate_any sets them up and ends them for anyone.
 */
final class TaskDelegations
{
    /** The longest period one delegation may cover (days). */
    public const MAX_DAYS = 366;

    /** @var array<string, Collection<int, TaskDelegation>> tenant:user:day => delegations running for the delegate */
    private array $running = [];

    public function __construct(private readonly TenantContext $tenants) {}

    /** @return list<string> the request types that may be delegated (inbox.delegation.types; empty = delegation off) */
    public static function kinds(): array
    {
        return InboxActions::kinds('delegation');
    }

    public static function switchedOn(): bool
    {
        return self::kinds() !== [];
    }

    /** Whether the actor may set up or end out-of-office periods for the delegator. */
    public function mayManageFor(User $actor, User $delegator): bool
    {
        return $actor->hasPermission('task.delegate_any')
            || ((int) $actor->id === (int) $delegator->id && $actor->hasPermission('task.delegate'));
    }

    /**
     * @param  list<string>  $types  request types to hand over (empty = every type switched on)
     */
    public function create(User $actor, User $delegator, User $delegate, string $from, string $to, array $types, string $reason): TaskDelegation
    {
        $switched = self::kinds();
        if ($switched === []) {
            throw new RuntimeException('Delegation is not switched on (Setup → Automation → Inbox actions → Delegate).');
        }
        if (! $this->mayManageFor($actor, $delegator)) {
            throw new AuthorizationException('You cannot set up an out-of-office period for this person.');
        }
        foreach ([$delegator, $delegate] as $person) {
            if (! $person->isActive() || $person->isPlatformAdmin() || (int) $person->tenant_id !== (int) $this->tenants->id()) {
                throw new RuntimeException('Choose active people of this organisation.');
            }
        }
        if ((int) $delegator->id === (int) $delegate->id) {
            throw new RuntimeException('Choose someone else to decide in your place.');
        }
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        if ($start->lt(today())) {
            throw new RuntimeException('The period cannot start in the past.');
        }
        if ($end->lt($start)) {
            throw new RuntimeException('The period must end on or after its first day.');
        }
        if ($start->diffInDays($end) + 1 > self::MAX_DAYS) {
            throw new RuntimeException('A delegation covers at most '.self::MAX_DAYS.' days.');
        }
        $types = array_values(array_unique(array_map('strval', array_filter($types, 'filled'))));
        if (array_diff($types, $switched) !== []) {
            throw new RuntimeException('Delegate only the request types switched on for delegation.');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new RuntimeException('Give the reason (up to 500 characters).');
        }

        return DB::transaction(function () use ($actor, $delegator, $delegate, $start, $end, $types, $reason) {
            // One out-of-office period at a time per person: serialise on the delegator.
            User::query()->whereKey($delegator->id)->lockForUpdate()->value('id');
            $overlap = fn (int $userId) => TaskDelegation::query()->where('delegator_id', $userId)->where('status', 'active')
                ->whereDate('starts_on', '<=', $end->toDateString())->whereDate('ends_on', '>=', $start->toDateString())->exists();
            if ($overlap($delegator->id)) {
                throw new RuntimeException('There is already an out-of-office period for '.$delegator->name.' over these dates; end it first.');
            }
            if ($overlap($delegate->id)) {
                throw new RuntimeException($delegate->name.' is out of office over these dates too; choose someone else.');
            }
            $delegation = TaskDelegation::create([
                'delegator_id' => $delegator->id, 'delegate_id' => $delegate->id, 'starts_on' => $start->toDateString(), 'ends_on' => $end->toDateString(),
                'types' => $types === [] ? null : $types, 'reason' => $reason, 'status' => 'active', 'created_by' => $actor->id,
            ]);
            InboxEvent::dispatch('workflow.task.delegated', $delegation, $delegation, [
                'kind' => 'Approvals', 'label' => $this->typesLabel($delegation), 'by' => $delegator->name,
                'from' => $start->format('d M Y'), 'to' => $end->format('d M Y'),
            ], [(int) $delegate->id]);
            $this->running = [];

            return $delegation;
        });
    }

    /** End a delegation early (or call off one that has not started). */
    public function revoke(TaskDelegation $delegation, User $actor, string $reason): TaskDelegation
    {
        $delegator = User::query()->find($delegation->delegator_id);
        if ($delegator === null || ! $this->mayManageFor($actor, $delegator)) {
            throw new AuthorizationException('You cannot end this out-of-office period.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give the reason for ending it.');
        }

        return DB::transaction(function () use ($delegation, $actor, $reason) {
            $locked = TaskDelegation::query()->whereKey($delegation->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'active' || $locked->ends_on->toDateString() < today()->toDateString()) {
                throw new RuntimeException('This out-of-office period is already over.');
            }
            $locked->withAuditReason($reason)->update(['status' => 'revoked', 'revoked_by' => $actor->id, 'revoked_at' => now(), 'revoke_reason' => mb_substr(trim($reason), 0, 500)]);
            $this->running = [];
            app(ApprovalCenter::class)->forget(User::query()->findOrFail($locked->delegate_id));

            return $locked;
        });
    }

    /**
     * Delegations the person decides for today: active, covering today, still switched on, whose delegator is active.
     *
     * @return Collection<int, TaskDelegation>
     */
    public function running(User $delegate): Collection
    {
        if (! self::switchedOn() || ! $this->tenants->has()) {
            return collect();
        }
        $key = $this->tenants->id().':'.$delegate->id.':'.today()->toDateString();

        return $this->running[$key] ??= TaskDelegation::query()->with('delegator')
            ->where('delegate_id', $delegate->id)->where('status', 'active')
            ->whereDate('starts_on', '<=', today()->toDateString())->whereDate('ends_on', '>=', today()->toDateString())
            ->orderBy('id')->get()
            ->filter(fn (TaskDelegation $d) => $d->delegator?->isActive() && $this->coveredKinds($d) !== [])
            ->values();
    }

    /** @return list<string> the request types this delegation hands over now (its own list within those switched on) */
    public function coveredKinds(TaskDelegation $delegation): array
    {
        $switched = self::kinds();
        $own = array_values(array_filter((array) ($delegation->types ?? [])));

        return $own === [] ? $switched : array_values(array_intersect($own, $switched));
    }

    public function covers(TaskDelegation $delegation, string $kind): bool
    {
        return in_array($kind, $this->coveredKinds($delegation), true);
    }

    /** A delegation the person may decide this kind of request through, re-checked at the moment of deciding. */
    public function runningFor(User $delegate, int $delegationId, string $kind): ?TaskDelegation
    {
        $this->running = [];

        return $this->running($delegate)->first(fn (TaskDelegation $d) => (int) $d->id === $delegationId && $this->covers($d, $kind));
    }

    /**
     * Whether a request is the delegate's own (raised by them, or about them): such requests are never delegated to them.
     */
    public function isOwnRequest(User $delegate, ?int $subjectEmployeeId, Model $record): bool
    {
        if ($subjectEmployeeId !== null && (int) Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($subjectEmployeeId)->value('user_id') === (int) $delegate->id) {
            return true;
        }
        if ($record instanceof WorkflowTask) {
            $instance = $record->relationLoaded('instance') ? $record->instance : $record->instance()->first();
            if ($instance === null) {
                return false;
            }
            if ((int) $instance->started_by === (int) $delegate->id) {
                return true;
            }

            return (int) app(NotificationContext::class)->employeeOf($instance->subject)?->user_id === (int) $delegate->id;
        }
        $attributes = $record->getAttributes();
        foreach (['requested_by', 'requester_id', 'raised_by', 'proposed_by', 'initiated_by', 'submitted_by'] as $column) {
            if (! empty($attributes[$column]) && (int) $attributes[$column] === (int) $delegate->id) {
                return true;
            }
        }
        if (! empty($attributes['employee_id'])) {
            return (int) Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($attributes['employee_id'])->value('user_id') === (int) $delegate->id;
        }

        return false;
    }

    /** The delegation through which the person may decide this workflow task, or null (none, or their own request). */
    public function forTask(WorkflowTask $task, User $delegate): ?TaskDelegation
    {
        if ($task->status !== TaskStatus::Pending) {
            return null;
        }
        foreach ($this->running($delegate) as $delegation) {
            if ($this->covers($delegation, 'workflow_task') && $delegation->delegator->can('act', $task) && ! $this->isOwnRequest($delegate, null, $task)) {
                return $delegation;
            }
        }

        return null;
    }

    /**
     * Narrows a WorkflowTask query to the pending tasks the person decides for their delegators (assigned to them or to
     * one of their roles; never runs the person started), or null when nothing is delegated to them.
     *
     * @return (Closure(Builder): Builder)|null
     */
    public function delegatedTasks(User $delegate): ?Closure
    {
        $delegators = $this->running($delegate)->filter(fn (TaskDelegation $d) => $this->covers($d, 'workflow_task'))->map(fn (TaskDelegation $d) => $d->delegator)->filter();
        if ($delegators->isEmpty()) {
            return null;
        }
        $userIds = $delegators->pluck('id')->map(fn ($id) => (int) $id)->all();
        $roleIds = $delegators->flatMap(fn (User $u) => $u->roles()->pluck('roles.id'))->map(fn ($id) => (int) $id)->unique()->values()->all();

        return fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereIn('assignee_id', $userIds)->when($roleIds !== [], fn (Builder $r) => $r->orWhereIn('assignee_role_id', $roleIds)))
            ->whereHas('instance', fn (Builder $i) => $i->where(fn (Builder $s) => $s->whereNull('started_by')->orWhere('started_by', '!=', $delegate->id)));
    }

    /**
     * Decide in the delegator's stead: the callback receives the delegator and runs signed in as them (their scope and
     * permissions), inside DelegatedDecision (the audit names the delegate, on behalf of the delegator).
     *
     * @template T
     *
     * @param  Closure(User): T  $decide
     * @return T
     */
    public function actFor(TaskDelegation $delegation, User $delegate, Closure $decide): mixed
    {
        $delegator = User::query()->findOrFail($delegation->delegator_id);

        return DelegatedDecision::run($delegation, $delegate, $delegator, fn () => ScreenAccess::as($delegator, fn () => $decide($delegator)));
    }

    /** Delegations the viewer may see on the Out of office screen: their own (either side), or everyone's with task.delegate_any. */
    public function visibleTo(User $viewer): Builder
    {
        return TaskDelegation::query()->with(['delegator', 'delegate'])
            ->when(! $viewer->hasPermission('task.delegate_any'), fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('delegator_id', $viewer->id)->orWhere('delegate_id', $viewer->id)));
    }

    public function typesLabel(TaskDelegation $delegation): string
    {
        $kinds = ApprovalCenter::kinds();
        $own = array_values(array_filter((array) ($delegation->types ?? [])));

        return $own === [] ? 'Every request type switched on for delegation' : implode(', ', array_map(fn (string $k) => $kinds[$k] ?? $k, $own));
    }
}
