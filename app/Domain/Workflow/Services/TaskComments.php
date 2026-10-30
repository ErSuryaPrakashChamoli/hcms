<?php

namespace App\Domain\Workflow\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Support\ApprovalItem;
use App\Domain\Experience\Support\InboxActions;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Workflow\Enums\TaskStatus;
use App\Domain\Workflow\Events\InboxEvent;
use App\Domain\Workflow\Models\TaskComment;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Domain\Workflow\Models\WorkflowTask;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * HROne setup parity P3 (Inbox → Discussion): the conversation about a request waiting for approval, for the request
 * types in `inbox.discussion.types`. One thread per workflow run (a workflow step) or per domain request. Only the
 * requester, the approvers (the request is in their Approval Center, or they decided a step of its run), people already
 * in the conversation and holders of task.view_all read it; the same people write in it while discussion is switched
 * on for its type. Messages are immutable and audited without their text; each one notifies the other participants
 * (workflow.task.commented, no message text in the notification).
 */
final class TaskComments
{
    public const MAX_LENGTH = 2000;

    /** Request types whose discussion stays between the person who raised the request and its approvers (the employee it is about does not take part). */
    private const RAISER_ONLY = ['compensation', 'letter', 'transfer'];

    /** @var array<string, Collection<int, TaskComment>> */
    private array $threads = [];

    /** The record a request's discussion hangs on: the workflow run for a workflow step, else the request itself. */
    public static function threadSubject(ApprovalItem $item): Model
    {
        if ($item->record instanceof WorkflowTask) {
            return ($item->record->relationLoaded('instance') ? $item->record->instance : $item->record->instance()->first()) ?? $item->record;
        }

        return $item->record;
    }

    /** @return Collection<int, TaskComment> oldest first */
    public function thread(Model $subject): Collection
    {
        return $this->threads[$this->key($subject)] ??= TaskComment::query()->with('author')->where('commentable_type', $subject->getMorphClass())
            ->where('commentable_id', $subject->getKey())->orderBy('id')->get();
    }

    /**
     * Load the threads of the items whose discussion is switched on, in one query (the Approval Center renders many).
     *
     * @param  iterable<ApprovalItem>  $items
     */
    public function prime(iterable $items): void
    {
        $subjects = collect($items)->filter(fn (ApprovalItem $i) => InboxActions::enabled('discussion', $i->type))->map(fn (ApprovalItem $i) => self::threadSubject($i))
            ->reject(fn (Model $m) => isset($this->threads[$this->key($m)]))->unique(fn (Model $m) => $this->key($m));
        if ($subjects->isEmpty()) {
            return;
        }
        $found = TaskComment::query()->with('author')->where(function ($q) use ($subjects) {
            foreach ($subjects->groupBy(fn (Model $m) => $m->getMorphClass()) as $type => $models) {
                $q->orWhere(fn ($w) => $w->where('commentable_type', $type)->whereIn('commentable_id', $models->map(fn (Model $m) => $m->getKey())->all()));
            }
        })->orderBy('id')->get()->groupBy(fn (TaskComment $c) => $c->commentable_type.':'.$c->commentable_id);
        foreach ($subjects as $subject) {
            $this->threads[$this->key($subject)] = $found->get($subject->getMorphClass().':'.$subject->getKey(), collect())->values();
        }
    }

    /** An approver writes on an item of their Approval Center (ApprovalCenter::find re-resolved it for them). */
    public function postOnItem(User $user, ApprovalItem $item, string $body): TaskComment
    {
        if (! InboxActions::enabled('discussion', $item->type)) {
            throw new AuthorizationException('Discussion is not switched on for this kind of request.');
        }

        return $this->write($user, self::threadSubject($item), $item->type, $body);
    }

    /** Anyone in the conversation writes in a thread (the requester, an approver of the run, a participant). */
    public function reply(User $user, Model $subject, string $kind, string $body): TaskComment
    {
        if (! InboxActions::enabled('discussion', $kind)) {
            throw new AuthorizationException('Discussion is not switched on for this kind of request.');
        }
        if (! $this->mayView($user, $subject, $kind)) {
            throw new AuthorizationException('You are not part of this conversation.');
        }

        return $this->write($user, $subject, $kind, $body);
    }

    public function mayView(User $user, Model $subject, string $kind): bool
    {
        if ($user->hasPermission('task.view_all') || in_array((int) $user->id, $this->requesterIds($subject, $kind), true)) {
            return true;
        }
        if (TaskComment::query()->where('commentable_type', $subject->getMorphClass())->where('commentable_id', $subject->getKey())->where('user_id', $user->id)->exists()) {
            return true;
        }
        if ($subject instanceof WorkflowInstance) {
            $tasks = WorkflowTask::query()->where('workflow_instance_id', $subject->id)->get();
            if ($tasks->contains(fn (WorkflowTask $t) => (int) $t->completed_by === (int) $user->id || (int) $t->assignee_id === (int) $user->id
                || ($t->status === TaskStatus::Pending && $t->isActionableBy($user)))) {
                return true;
            }
        } elseif ((int) ($subject->getAttributes()['reviewed_by'] ?? 0) === (int) $user->id) {
            return true;
        }

        // An approver of the request: it is in their Approval Center.
        return app(ApprovalCenter::class)->pending($user)->contains(fn (ApprovalItem $i) => self::threadSubject($i)->is($subject));
    }

    /** @return list<int> who raised the request (and, except for confidential kinds, the employee it is about) */
    public function requesterIds(Model $subject, string $kind): array
    {
        if ($subject instanceof WorkflowInstance) {
            return array_values(array_filter([(int) $subject->started_by]));
        }
        $attributes = $subject->getAttributes();
        $ids = [(int) ($attributes['requested_by'] ?? 0), (int) ($attributes['proposed_by'] ?? 0)];
        if (! in_array($kind, self::RAISER_ONLY, true) && ! empty($attributes['employee_id'])) {
            $ids[] = (int) Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($attributes['employee_id'])->value('user_id');
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private function write(User $user, Model $subject, string $kind, string $body): TaskComment
    {
        $body = trim($body);
        if ($body === '') {
            throw new InvalidArgumentException('Write a message first.');
        }
        if (mb_strlen($body) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('A message can be at most '.self::MAX_LENGTH.' characters.');
        }
        $previous = $this->thread($subject)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $comment = TaskComment::create(['commentable_type' => $subject->getMorphClass(), 'commentable_id' => $subject->getKey(), 'kind' => $kind, 'user_id' => $user->id, 'body' => $body]);
        unset($this->threads[$this->key($subject)]);

        $recipients = [...$this->requesterIds($subject, $kind), ...$previous];
        if ($subject instanceof WorkflowInstance) {
            $recipients = [...$recipients, ...WorkflowTask::query()->where('workflow_instance_id', $subject->id)->where('status', TaskStatus::Pending)->whereNotNull('assignee_id')->pluck('assignee_id')->map(fn ($id) => (int) $id)->all()];
        }
        $recipients = array_values(array_diff(array_unique($recipients), [(int) $user->id]));
        $about = $subject instanceof WorkflowInstance ? ($subject->subject()->first() ?? $subject) : $subject;
        $label = method_exists($subject, 'auditLabel') ? $subject->auditLabel() : null;
        InboxEvent::dispatch('workflow.task.commented', $about, $comment, ['kind' => ApprovalCenter::kinds()[$kind] ?? $kind, 'label' => $label, 'by' => $user->name], $recipients);

        return $comment;
    }

    private function key(Model $subject): string
    {
        return app(TenantContext::class)->id().':'.$subject->getMorphClass().':'.$subject->getKey();
    }
}
