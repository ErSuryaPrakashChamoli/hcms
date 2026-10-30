<?php

namespace App\Domain\Experience\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Models\AuditEventChange;
use App\Domain\Expense\Models\ExpenseReportPart;
use App\Domain\Expense\Models\TravelRequestPart;
use App\Domain\Experience\Contracts\DescribesApprovalRoute;
use App\Domain\Experience\Contracts\RequestType;
use App\Domain\Experience\Enums\RequestStatus;
use App\Domain\Experience\Support\ApprovalItem;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Workflow\Enums\InstanceStatus;
use App\Domain\Workflow\Models\RequestReturn;
use App\Domain\Workflow\Models\WorkflowAction;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Domain\Workflow\Models\WorkflowTask;
use App\Domain\Workflow\Support\RequestWorkflows;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;

/**
 * HROne parity RQ-2 (matrix 20 §20.1.1 #8, §20.2.1 #20, #30 and the activity logs of §20.1.3 #11, §20.1.4 #12, §20.1.11 #9,
 * §20.1.15 #8): a request's trail — read only.
 *
 * - forOwn(): the requester's own request only (the type's ownRecords() holds it and its module list opens for them).
 * - forItem(): an approver's Approval Center item (ApprovalCenter::find already proved it is theirs to decide).
 *
 * Entries: submitted (and by whom, when raised for the person); every decision, cancellation and status change from the
 * audit trail of the request (and of its parts); the steps of the workflow runs that decide it (started, waiting with,
 * approved / rejected / done by, decided automatically, sent back, resubmitted, moved to someone else, finished) from
 * `workflow_actions`; send-backs of requests the Approval Center decides; reminders and colleagues informed. Never a note
 * an approver wrote with a decision — the only text shown is the question of a send-back, which was addressed to the
 * requester — and never anything about other people's requests. "Now": while it is pending, whom it waits with and by when
 * (RequestApprovers).
 */
final class RequestTimeline
{
    /** Entries of the same kind this close together are one event (a service's own record and the model's status change). */
    private const SAME_EVENT_SECONDS = 120;

    public function __construct(private readonly RequestRegistry $registry, private readonly RequestApprovers $approvers) {}

    /** Whether the record is the signed-in person's own request of this type, in a list that opens for them. */
    public function isOwn(User $viewer, RequestType $type, Model $record): bool
    {
        if (! auth()->check() || (int) auth()->id() !== (int) $viewer->getKey() || ! $type->historyVisibleTo($viewer)) {
            return false;
        }
        try {
            $query = $type->ownRecords($viewer, $this->registry->employeeOf($viewer));
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        return $query instanceof Builder && (clone $query)->whereKey($record->getKey())->exists()
            && (! $type instanceof DescribesApprovalRoute || $type->owns($record));
    }

    /**
     * The requester's own request, by "type:id" (the key My requests uses), or null when it is not theirs.
     *
     * @return array{0: RequestType, 1: Model}|null
     */
    public function resolveOwn(User $viewer, string $key): ?array
    {
        [$typeKey, $id] = array_pad(explode(':', $key, 2), 2, null);
        $type = $typeKey !== null ? $this->registry->find($typeKey) : null;
        if ($type === null || ! ctype_digit((string) $id) || $type->model() === null) {
            return null;
        }
        try {
            $query = $type->ownRecords($viewer, $this->registry->employeeOf($viewer));
            $record = $query instanceof Builder ? (clone $query)->whereKey((int) $id)->first() : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return $record !== null && $this->isOwn($viewer, $type, $record) ? [$type, $record] : null;
    }

    /** @return array{entries: list<array<string, mixed>>, pending: bool, waiting: list<string>, due: ?CarbonInterface, via: ?string}|null */
    public function forOwn(User $viewer, RequestType $type, Model $record): ?array
    {
        return $this->isOwn($viewer, $type, $record) ? $this->build($type, $record, $viewer, true) : null;
    }

    /**
     * An approver's view of the trail of an item in their Approval Center (the caller re-resolved the item for them).
     *
     * @return array{entries: list<array<string, mixed>>, pending: bool, waiting: list<string>, due: ?CarbonInterface, via: ?string, type: ?RequestType}|null
     */
    public function forItem(User $approver, ApprovalItem $item): ?array
    {
        $record = $this->requestOf($item->record);
        if ($record === null) {
            return null;
        }
        $type = $this->registry->forRecord($record);
        if ($type === null) {
            // Not a registered request type (a workflow on another subject, a letter …): the run's own trail only.
            return $item->record instanceof WorkflowTask ? ['type' => null] + $this->build(null, $record, $approver, false, $this->runOf($item->record)) : null;
        }

        return ['type' => $type] + $this->build($type, $record, $approver, false);
    }

    /** The request an Approval Center record stands for (a workflow step's subject, a report part's report, a trip part's trip). */
    public function requestOf(mixed $record): ?Model
    {
        return match (true) {
            $record instanceof WorkflowTask => ($run = $this->runOf($record)) !== null ? ($run->subject()->withoutGlobalScope(AccessScope::class)->first() ?? $run) : null,
            $record instanceof ExpenseReportPart => $record->report()->withoutGlobalScope(AccessScope::class)->first(),
            $record instanceof TravelRequestPart => $record->trip()->withoutGlobalScope(AccessScope::class)->first(),
            $record instanceof Model => $record,
            default => null,
        };
    }

    /** @return array{entries: list<array<string, mixed>>, pending: bool, waiting: list<string>, due: ?CarbonInterface, via: ?string} */
    private function build(?RequestType $type, Model $record, User $viewer, bool $requester, ?WorkflowInstance $onlyRun = null): array
    {
        $route = $type instanceof DescribesApprovalRoute ? $type : null;
        $records = $route !== null ? $route->waitingRecords($record) : [$record];
        $entries = [];

        if ($type === null) {
            // Not a registered request (a workflow on some other record): only its run is told, never the record's own history.
            $entries[] = $this->entry($onlyRun?->started_at, 'submitted', 'Started', $onlyRun?->started_by ? User::query()->find($onlyRun->started_by)?->name : null, null, 'info');
            $records = [];
        } else {
            // Submitted.
            $dates = $this->attempt(fn () => $type->dates($record));
            $raisedAt = $dates['requested'] ?? $record->getAttribute('created_at');
            $raisedBy = (int) ($record->getAttributes()['requested_by'] ?? $record->getAttributes()['submitted_by'] ?? 0);
            $raiser = $raisedBy > 0 ? User::query()->find($raisedBy) : null;
            $ownEmployeeUser = $route?->employeeOf($record)?->user_id;
            $entries[] = $this->entry($raisedAt, 'submitted', 'Submitted', $raiser !== null && (int) $raiser->id !== (int) $ownEmployeeUser ? $raiser->name.($requester ? ' (on your behalf)' : '') : ($requester ? 'You' : $raiser?->name), null, 'info');

            // Decisions and status changes from the audit trail of the request and its parts.
            foreach ($this->auditEvents([$record, ...array_filter($records, fn (Model $m) => ! $m->is($record))]) as $event) {
                $classified = $this->classify($event, $route, $viewer, $requester);
                if ($classified !== null) {
                    $entries[] = $classified;
                }
            }
        }

        // The workflow runs that decide it.
        $runs = $onlyRun !== null ? collect([$onlyRun]) : RequestWorkflows::decidingRuns(...$records);
        foreach ($runs as $run) {
            foreach ($this->runEntries($run, $viewer, $requester) as $entry) {
                $entries[] = $entry;
            }
        }

        // Send-backs of requests the Approval Center decides (a workflow's own send-back is in its run's log).
        foreach ($records === [] ? [] : RequestReturn::query()->with('returner')->where(function ($q) use ($records) {
            foreach ($records as $r) {
                $q->orWhere(fn ($w) => $w->where('subject_type', $r->getMorphClass())->where('subject_id', $r->getKey()));
            }
        })->orderBy('id')->get() as $return) {
            $entries[] = $this->entry($return->returned_at, 'sent_back', $requester ? 'Sent back to you' : 'Sent back to the requester', $return->returner?->name, $return->note, 'warning');
            if ($return->resubmitted_at !== null) {
                $entries[] = $this->entry($return->resubmitted_at, 'resubmitted', 'Resubmitted', $requester ? 'You' : User::query()->find($return->resubmitted_by)?->name, null, 'info');
            }
        }

        $entries = $this->collapse($entries);

        $pending = $type !== null ? $type->status($record) === RequestStatus::Pending : ($onlyRun !== null && in_array($onlyRun->status, InstanceStatus::inFlight(), true));
        $now = $pending && $type !== null ? $this->approvers->current($type, $record) : ['via' => null, 'labels' => [], 'due' => null];
        if ($pending && $type === null && $onlyRun !== null) {
            $tasks = WorkflowTask::query()->with(['assignee', 'assigneeRole'])->where('workflow_instance_id', $onlyRun->id)->where('status', 'pending')->get();
            $now = ['via' => 'workflow', 'labels' => $tasks->map(fn (WorkflowTask $t) => $t->assigneeLabel())->unique()->values()->all(), 'due' => $tasks->pluck('due_at')->filter()->sort()->first()];
        }

        return [
            'entries' => $entries,
            'pending' => $pending,
            'waiting' => $now['labels'],
            'due' => $now['due'],
            'via' => $now['via'],
        ];
    }

    /**
     * @param  list<Model>  $records
     * @return Collection<int, AuditEvent>
     */
    private function auditEvents(array $records): Collection
    {
        $records = array_values(array_filter($records, fn (Model $m) => $m->exists));
        if ($records === []) {
            return collect();
        }

        return AuditEvent::query()->with('fieldChanges')->where(function ($q) use ($records) {
            foreach ($records as $r) {
                $types = [$r::class, ...(method_exists($r, 'auditEntityAliases') ? $r->auditEntityAliases() : [])];
                $q->orWhere(fn ($w) => $w->whereIn('entity_type', $types)->where('entity_id', (string) $r->getKey()));
            }
        })->orderBy('occurred_at')->orderBy('id')->limit(300)->get();
    }

    /** @return array<string, mixed>|null */
    private function classify(AuditEvent $event, ?DescribesApprovalRoute $route, User $viewer, bool $requester): ?array
    {
        $action = $event->action instanceof AuditAction ? $event->action : null;
        $value = $action?->value ?? (string) ($event->getAttributes()['action'] ?? '');
        $self = (int) $event->actor_id === (int) $viewer->getKey();
        $by = $self && $requester ? 'You' : ($event->actor_name ?: ($event->actor_id === null ? 'Automatically' : null));
        if ($value === AuditAction::RequestReminderSent->value) {
            return $this->entry($event->occurred_at, 'reminded', 'Reminder sent to the approvers', $by, null, 'info');
        }
        if ($value === AuditAction::RequestIntimated->value) {
            $count = count((array) ($event->metadata['recipients'] ?? []));

            return $this->entry($event->occurred_at, 'intimated', $count === 1 ? 'A colleague was informed' : $count.' colleagues were informed', $by, null, 'info');
        }
        // A change of the request's status (the model's own record of it).
        $field = $route?->statusField() ?? 'status';
        $change = $event->fieldChanges->first(fn (AuditEventChange $c) => $c->field === $field);
        if ($change !== null && in_array($value, [AuditAction::Update->value, AuditAction::StatusChange->value, 'REQUEST_STATUS_CHANGED'], true) && $change->after !== null) {
            return $this->statusEntry($event, (string) $change->after, $route, $by);
        }
        $kind = match (true) {
            (bool) preg_match('/CANCEL_REQUESTED$/', $value) => 'cancel_requested',
            (bool) preg_match('/(^|_)APPROVED$/', $value) => 'approved',
            (bool) preg_match('/(^|_)REJECTED$/', $value) => 'rejected',
            (bool) preg_match('/(^|_)(CANCELLED|WITHDRAWN)$/', $value) => 'withdrawn',
            (bool) preg_match('/(^|_)ESCALATED$/', $value) => 'escalated',
            (bool) preg_match('/(^|_)REASSIGNED$/', $value) => 'reassigned',
            (bool) preg_match('/(^|_)RESOLVED$/', $value) => 'resolved',
            (bool) preg_match('/(^|_)CLOSED$/', $value) => 'closed',
            default => null,
        };
        if ($kind === null) {
            return null;
        }
        [$title, $tone] = match ($kind) {
            'cancel_requested' => ['Cancellation requested', 'warning'],
            'approved' => ['Approved', 'success'],
            'rejected' => ['Rejected', 'danger'],
            'withdrawn' => ['Withdrawn or cancelled', 'neutral'],
            'escalated' => ['Escalated', 'warning'],
            'reassigned' => ['Moved to someone else', 'info'],
            'resolved' => ['Resolved', 'success'],
            'closed' => ['Closed', 'neutral'],
        };

        return $this->entry($event->occurred_at, $kind, $title, $by, null, $tone);
    }

    /** @return array<string, mixed> */
    private function statusEntry(AuditEvent $event, string $after, ?DescribesApprovalRoute $route, ?string $by): array
    {
        $mapped = $route instanceof RequestType ? ($route->statusMap()[$after] ?? null) : null;
        $label = $route?->statusText($after) ?? ucfirst(str_replace('_', ' ', $after));
        [$kind, $title, $tone] = match (true) {
            $after === 'cancel_requested' => ['cancel_requested', 'Cancellation requested', 'warning'],
            $mapped === RequestStatus::Approved => ['approved', $label === 'Approved' ? 'Approved' : 'Approved · '.$label, 'success'],
            $mapped === RequestStatus::Rejected => ['rejected', 'Rejected', 'danger'],
            $mapped === RequestStatus::Withdrawn => ['withdrawn', $label, 'neutral'],
            $mapped === RequestStatus::Draft => ['draft', $label, 'neutral'],
            default => ['status', $label, 'info'],
        };

        return $this->entry($event->occurred_at, $kind, $title, $by, null, $tone);
    }

    /** @return list<array<string, mixed>> */
    private function runEntries(WorkflowInstance $run, User $viewer, bool $requester): array
    {
        $tasks = WorkflowTask::query()->where('workflow_instance_id', $run->id)->get()->keyBy('id');
        $name = $run->workflow?->name ?? 'Approval workflow';
        $out = [];
        foreach (WorkflowAction::query()->with('actor')->where('workflow_instance_id', $run->id)->orderBy('id')->get() as $action) {
            $payload = (array) ($action->payload ?? []);
            $task = isset($payload['task_id']) ? $tasks->get($payload['task_id']) : null;
            $step = $task?->title ? ' · '.$task->title : '';
            $self = (int) $action->actor_id === (int) $viewer->getKey();
            $by = $self && $requester ? 'You' : $action->actor?->name;
            $entry = match ($action->action) {
                'started' => $this->entry($action->created_at, 'workflow', 'Approval workflow started: '.$name, null, null, 'info'),
                'task.created' => $this->entry($action->created_at, 'assigned', 'Waiting with '.($payload['assignee'] ?? 'an approver').$step, null, null, 'info'),
                'task.approved' => $this->entry($action->created_at, 'approved', 'Approved'.$step, $by, null, 'success'),
                'task.rejected' => $this->entry($action->created_at, 'rejected', 'Rejected'.$step, $by, null, 'danger'),
                'task.completed' => $this->entry($action->created_at, 'done', 'Done'.$step, $by, null, 'success'),
                // HROne parity RQ-8: a system task closed as not relevant (its reason stays with the approvers' log) and brought back.
                'task.not_relevant' => $this->entry($action->created_at, 'done', 'Marked not relevant'.$step, $by, null, 'neutral'),
                'task.restored' => $this->entry($action->created_at, 'assigned', 'Back in the inbox'.$step, $by, null, 'info'),
                'task.auto_decided' => $this->entry($action->created_at, ($payload['decision'] ?? '') === 'rejected' ? 'rejected' : 'approved',
                    (($payload['decision'] ?? '') === 'rejected' ? 'Rejected' : 'Approved').' automatically (the turnaround time passed)'.$step, null, null, ($payload['decision'] ?? '') === 'rejected' ? 'danger' : 'success'),
                // The question of a send-back is addressed to the requester: it is the one approver text shown.
                'task.sent_back' => $this->entry($action->created_at, 'sent_back', $requester ? 'Sent back to you' : 'Sent back to the requester', $by, isset($payload['note']) ? (string) $payload['note'] : null, 'warning'),
                'resubmitted' => $this->entry($action->created_at, 'resubmitted', 'Resubmitted', $by, null, 'info'),
                'task.reassigned' => $this->entry($action->created_at, 'reassigned', 'Moved from '.($payload['from'] ?? 'an approver').' to '.($payload['to'] ?? 'another approver').$step, $by, null, 'info'),
                'completed' => $this->entry($action->created_at, 'workflow_done', 'Workflow finished: '.str_replace('_', ' ', (string) ($payload['outcome'] ?? 'completed')), null, null, 'neutral'),
                'cancelled' => $this->entry($action->created_at, 'workflow_cancelled', 'Workflow cancelled', null, null, 'neutral'),
                'failed' => $this->entry($action->created_at, 'workflow_failed', 'The workflow stopped; HR has to look at it', null, null, 'danger'),
                default => null,
            };
            if ($entry !== null) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * Oldest first; an entry repeating the kind of the one just before it within SAME_EVENT_SECONDS is the same event
     * (a service's own audit record next to the model's status change): kept once, with whoever made it.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private function collapse(array $entries): array
    {
        $entries = array_values(array_filter($entries, fn (array $e) => $e['at'] instanceof CarbonInterface));
        usort($entries, fn (array $a, array $b) => [$a['at']->getTimestamp(), $a['order']] <=> [$b['at']->getTimestamp(), $b['order']]);
        $out = [];
        $mergeable = ['approved', 'rejected', 'withdrawn', 'cancel_requested', 'sent_back', 'resubmitted'];
        foreach ($entries as $entry) {
            $last = $out === [] ? null : $out[array_key_last($out)];
            // Two steps of a workflow (each named) stay apart; a generic record of the same outcome joins the step it records.
            $generic = $last !== null && ($last['title'] === $entry['title'] || ! str_contains($last['title'], ' · ') || ! str_contains($entry['title'], ' · '));
            if ($last !== null && $last['kind'] === $entry['kind'] && in_array($entry['kind'], $mergeable, true) && $generic
                && abs($entry['at']->getTimestamp() - $last['at']->getTimestamp()) <= self::SAME_EVENT_SECONDS) {
                $out[array_key_last($out)]['by'] ??= $entry['by'];
                $out[array_key_last($out)]['note'] ??= $entry['note'];

                continue;
            }
            $out[] = $entry;
        }

        return array_map(fn (array $e) => array_diff_key($e, ['order' => true]), $out);
    }

    /** @return array<string, mixed> */
    private function entry(mixed $at, string $kind, string $title, ?string $by, ?string $note, string $tone): array
    {
        static $order = 0;

        return ['at' => $at instanceof CarbonInterface ? $at : null, 'kind' => $kind, 'title' => $title, 'by' => $by,
            'note' => $note !== null && trim($note) !== '' ? mb_strimwidth(trim($note), 0, 500, '…') : null, 'tone' => $tone, 'order' => $order++];
    }

    private function runOf(WorkflowTask $task): ?WorkflowInstance
    {
        return $task->relationLoaded('instance') ? $task->instance : $task->instance()->with('workflow')->first();
    }

    private function attempt(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
