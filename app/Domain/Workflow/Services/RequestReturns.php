<?php

namespace App\Domain\Workflow\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Support\ApprovalItem;
use App\Domain\Experience\Support\InboxActions;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Workflow\Enums\InstanceStatus;
use App\Domain\Workflow\Events\InboxEvent;
use App\Domain\Workflow\Models\RequestReturn;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Domain\Workflow\Models\WorkflowTask;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * HROne setup parity P3 (Inbox → Send back). An approver returns a request to its requester with a note, for the request
 * types switched on in `inbox.send_back.types` that support it (ApprovalCenter::sendBackKinds()):
 *   - a workflow step: the run waits as Returned (WorkflowEngine::sendBack) and resumes at the same step on resubmission;
 *   - a domain request (leave, time office, loan, reimbursement claim): it keeps its pending status, so its balance
 *     reservation, overlap checks and payroll inputs are untouched, and leaves every approver's queue while the return
 *     is open; its own screens may still decide it.
 * Each send-back is a RequestReturn (open → resubmitted, or closed when the request was decided or withdrawn meanwhile),
 * audited, and notified (workflow.task.sent_back / workflow.task.resubmitted). Nobody sends back their own request, and
 * only the requester resubmits.
 */
final class RequestReturns
{
    /** @var array<int, array<string, RequestReturn>> tenant => (morph:id => open return of a domain request) */
    private array $open = [];

    public function __construct(private readonly WorkflowEngine $engine) {}

    /** Whether the approver may send this Approval Center item back now. */
    public function offers(ApprovalItem $item, User $user): bool
    {
        if (! InboxActions::enabled('send_back', $item->type) || ! in_array($item->type, ApprovalCenter::sendBackKinds(), true)
            || (! $item->can('approve') && ! $item->can('reject'))) {
            return false;
        }
        if ($item->record instanceof WorkflowTask) {
            $instance = $item->record->relationLoaded('instance') ? $item->record->instance : $item->record->instance()->first();

            return $item->record->type === 'approval' && $instance?->started_by !== null && (int) $instance->started_by !== (int) $user->id;
        }
        $requester = $this->requesterOf($item->record);

        return ($item->record->getAttributes()['status'] ?? null) === 'pending' && $requester !== null && $requester !== (int) $user->id;
    }

    /** The person a domain request goes back to: who raised it, else the employee it is about. */
    public function requesterOf(Model $record): ?int
    {
        $attributes = $record->getAttributes();
        if (! empty($attributes['requested_by'])) {
            return (int) $attributes['requested_by'];
        }
        $userId = isset($attributes['employee_id']) ? Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($attributes['employee_id'])->value('user_id') : null;

        return $userId ? (int) $userId : null;
    }

    /** Send an Approval Center item back (ApprovalDecisions: the item was re-resolved for this user). */
    public function sendBack(ApprovalItem $item, User $actor, string $note): RequestReturn
    {
        if (! $this->offers($item, $actor)) {
            throw new AuthorizationException('This request cannot be sent back.');
        }

        return $item->record instanceof WorkflowTask
            ? $this->sendBackTask($item->record, $actor, $note)
            : $this->sendBackRecord($item->record, $item->type, $actor, $note);
    }

    /** Send a workflow step back to the run's initiator (also from the task inbox). */
    public function sendBackTask(WorkflowTask $task, User $actor, string $note): RequestReturn
    {
        $note = $this->note($note);
        if (! InboxActions::enabled('send_back', 'workflow_task')) {
            throw new AuthorizationException('Sending back is not switched on for workflow steps.');
        }

        return DB::transaction(function () use ($task, $actor, $note) {
            $instance = $this->engine->sendBack($task, $note, $actor);

            return $this->record('workflow_task', $instance, $instance->auditLabel(), (int) $instance->started_by, $actor, $note);
        });
    }

    /** Resubmit a request sent back to the user, with their answer. */
    public function resubmit(RequestReturn $return, User $actor, ?string $response = null): RequestReturn
    {
        $response = filled($response) ? mb_substr(trim((string) $response), 0, 2000) : null;

        $result = DB::transaction(function () use ($return, $actor, $response) {
            $return = RequestReturn::query()->whereKey($return->getKey())->lockForUpdate()->firstOrFail();
            if (! $return->isOpen()) {
                throw new RuntimeException('This request is no longer waiting for you.');
            }
            if ((int) $return->requester_id !== (int) $actor->id) {
                throw new AuthorizationException('Only the person it was sent back to resubmits it.');
            }
            $subject = $return->subject()->withoutGlobalScope(AccessScope::class)->first();
            if (! $this->stillWaiting($return, $subject)) {
                // Decided or withdrawn meanwhile: the return is closed (kept), and the person is told below.
                $return->withAuditReason('The request was decided or withdrawn meanwhile.')->update(['status' => 'closed']);
                $this->open = [];

                return null;
            }
            if ($subject instanceof WorkflowInstance) {
                $this->engine->resubmit($subject, $response, $actor);
            }
            $return->withAuditReason($response)->update(['status' => 'resubmitted', 'response' => $response, 'resubmitted_by' => $actor->id, 'resubmitted_at' => now()]);
            $this->open = [];
            InboxEvent::dispatch('workflow.task.resubmitted', $subject instanceof WorkflowInstance ? ($subject->subject()->first() ?? $subject) : $subject, $return,
                ['kind' => ApprovalCenter::kinds()[$return->kind] ?? $return->kind, 'label' => $return->subject_label, 'by' => $actor->name], [(int) $return->returned_by]);

            return $return->refresh();
        });

        return $result ?? throw new RuntimeException('This request was decided or withdrawn meanwhile.');
    }

    /** The open return of a domain request, if any. */
    public function openFor(Model $record): ?RequestReturn
    {
        return $this->openMap()[$record->getMorphClass().':'.$record->getKey()] ?? null;
    }

    /** Whether an Approval Center item waits for its requester (a domain request with an open return). */
    public function isReturned(ApprovalItem $item): bool
    {
        return ! $item->record instanceof WorkflowTask && $this->openFor($item->record) !== null;
    }

    /**
     * Requests sent back to the user that still wait for them (decided or withdrawn ones drop out).
     *
     * @return Collection<int, RequestReturn>
     */
    public function waitingFor(User $user): Collection
    {
        return RequestReturn::query()->with('returner')->where('status', 'open')->where('requester_id', $user->id)->latest('returned_at')->get()
            ->filter(fn (RequestReturn $r) => $this->stillWaiting($r, $r->subject()->withoutGlobalScope(AccessScope::class)->first()))->values();
    }

    public function stillWaiting(RequestReturn $return, ?Model $subject): bool
    {
        return match (true) {
            $subject === null => false,
            $subject instanceof WorkflowInstance => $subject->status === InstanceStatus::Returned,
            default => ($subject->getAttributes()['status'] ?? null) === 'pending',
        };
    }

    private function sendBackRecord(Model $record, string $kind, User $actor, string $note): RequestReturn
    {
        $note = $this->note($note);

        return DB::transaction(function () use ($record, $kind, $actor, $note) {
            $locked = $record->newQuery()->withoutGlobalScope(AccessScope::class)->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            if (($locked->getAttributes()['status'] ?? null) !== 'pending') {
                throw new RuntimeException('This request is no longer pending.');
            }
            if (RequestReturn::query()->where('subject_type', $locked->getMorphClass())->where('subject_id', $locked->getKey())->where('status', 'open')->lockForUpdate()->exists()) {
                throw new RuntimeException('This request is already waiting for its requester.');
            }
            $requester = $this->requesterOf($locked) ?? throw new RuntimeException('This request has nobody to send it back to.');
            if ($requester === (int) $actor->id) {
                throw new AuthorizationException('You cannot send back your own request.');
            }
            $label = method_exists($locked, 'auditLabel') ? $locked->auditLabel() : class_basename($locked).' #'.$locked->getKey();

            return $this->record($kind, $locked, $label, $requester, $actor, $note);
        });
    }

    private function record(string $kind, Model $subject, ?string $label, int $requester, User $actor, string $note): RequestReturn
    {
        $return = (new RequestReturn([
            'kind' => $kind, 'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey(), 'subject_label' => $label !== null ? mb_substr($label, 0, 255) : null,
            'requester_id' => $requester, 'returned_by' => $actor->id, 'returned_at' => now(), 'note' => $note, 'status' => 'open',
        ]))->withAuditReason($note);
        $return->save();
        $this->open = [];
        $about = $subject instanceof WorkflowInstance ? ($subject->subject()->first() ?? $subject) : $subject;
        InboxEvent::dispatch('workflow.task.sent_back', $about, $return, ['kind' => ApprovalCenter::kinds()[$kind] ?? $kind, 'label' => $label, 'by' => $actor->name], [$requester]);

        return $return;
    }

    private function note(string $note): string
    {
        $note = trim($note);
        if ($note === '') {
            throw new InvalidArgumentException('Say what the requester should change.');
        }

        return mb_substr($note, 0, 2000);
    }

    /** @return array<string, RequestReturn> */
    private function openMap(): array
    {
        return $this->open[(int) app(TenantContext::class)->id()] ??= RequestReturn::query()->where('status', 'open')->where('kind', '!=', 'workflow_task')->get()
            ->keyBy(fn (RequestReturn $r) => $r->subject_type.':'.$r->subject_id)->all();
    }
}
