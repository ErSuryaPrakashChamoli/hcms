<?php

namespace App\Domain\ServiceDesk\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Grievance\Models\Grievance;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\ServiceDesk\Events\ServiceDeskEvent;
use App\Domain\ServiceDesk\Exceptions\ServiceDeskRuleViolation;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Models\TicketAccessGrant;
use App\Domain\ServiceDesk\Models\TicketCategory;
use App\Domain\ServiceDesk\Models\TicketComment;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Support\Numbering\NumberSequences;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * The HR service desk (§48, Phase 12): the case operations on a request. Every operation:
 * - is authorised by CaseAccess (and never on one's own case as HR);
 * - moves the status only through RequestLifecycle (map, lock, audit, history, SLA);
 * - notifies with references only (number, service, status).
 *
 * Intake is ServiceRequests, domain hand-off DomainActionExecutor, assignment CaseAssignment,
 * scheduled SLA work ServiceDeskProcessor.
 */
final class ServiceDesk
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly WorkflowEngine $workflows,
        private readonly RequestLifecycle $lifecycle,
        private readonly CaseAccess $access,
        private readonly CaseAssignment $assignment,
        private readonly CaseAttachments $attachments,
        private readonly DomainActionExecutor $executor,
        private readonly NumberSequences $numbers,
    ) {}

    /** Legacy "Ask HR" ticket in a request category (no catalogue service). */
    public function open(Employee $employee, TicketCategory $category, string $subject, string $description, string $priority = 'normal', ?User $raiser = null, ?string $idempotencyKey = null): Ticket
    {
        return app(ServiceRequests::class)->openGeneric($employee, $category, $subject, $description, $priority, $raiser, $idempotencyKey);
    }

    public function assign(Ticket $ticket, User $assignee, ?User $actor = null, ?string $reason = null, ?int $expectedVersion = null): Ticket
    {
        return $this->assignment->assign($ticket, $assignee, $actor ?? $this->actor(), $reason, $expectedVersion);
    }

    /** First acknowledgement by HR: records the first response; a submitted request becomes acknowledged. */
    public function acknowledge(Ticket $ticket, User $actor, ?int $expectedVersion = null): Ticket
    {
        $this->assertWorker($actor, $ticket);

        return $this->lifecycle->move($ticket, null, $actor, AuditAction::RequestStatusChanged, null, [], 'user', $expectedVersion,
            guard: function (Ticket $fresh) {
                if ($fresh->acknowledged_at !== null || ! $fresh->isOpen()) {
                    throw new ServiceDeskRuleViolation('The request is already acknowledged.');
                }
                $fresh->fill(['acknowledged_at' => now(), 'first_responded_at' => $fresh->first_responded_at ?? now()]);
                if ($fresh->status === 'submitted') {
                    $fresh->status = 'acknowledged';
                }
            },
            notify: ['event' => 'servicedesk.ticket.acknowledged', 'recipients' => [$this->employeeUserId($ticket)]],
            metadata: ['event' => 'REQUEST_ACKNOWLEDGED'],
            asWorker: true,
        );
    }

    public function start(Ticket $ticket, User $actor, ?int $expectedVersion = null): Ticket
    {
        $this->assertWorker($actor, $ticket);

        return $this->lifecycle->move($ticket, 'in_progress', $actor, AuditAction::RequestStatusChanged, null, [], 'user', $expectedVersion, asWorker: true);
    }

    public function comment(Ticket $ticket, User $author, string $body, string|bool $visibility = 'employee', UploadedFile|string|null $attachment = null, ?string $attachmentName = null): TicketComment
    {
        $visibility = is_bool($visibility) ? ($visibility ? 'internal' : 'employee') : $visibility;
        if (! in_array($visibility, $this->access->commentWritable($author, $ticket), true)) {
            throw new ServiceDeskRuleViolation('You cannot post '.($visibility === 'employee' ? 'a reply' : 'that kind of note').' on this case.');
        }
        if (trim($body) === '') {
            throw new ServiceDeskRuleViolation('Write a message.');
        }

        return DB::transaction(function () use ($ticket, $author, $body, $visibility, $attachment, $attachmentName) {
            // The request row first, the audit chain last (lock order).
            $fresh = Ticket::query()->withoutGlobalScope(AccessScope::class)->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            if (in_array($fresh->status, ['closed', 'cancelled'], true)) {
                throw new ServiceDeskRuleViolation('The request is '.$fresh->status.'; reopen it to continue the conversation.');
            }
            $stored = filled($attachment) ? $this->attachments->store($fresh, $attachment, $attachmentName) : [];
            $comment = TicketComment::query()->create(['ticket_id' => $fresh->id, 'author_id' => $author->id, 'body' => $body, 'visibility' => $visibility] + $stored);

            $byRequester = ($this->access->isOwn($author, $fresh) || (int) $fresh->raised_by === (int) $author->id) && ! $this->access->canWork($author, $fresh);
            if ($byRequester && $fresh->status === 'waiting_employee') {
                $this->lifecycle->move($fresh, 'in_progress', $author, AuditAction::RequestStatusChanged, null, [], 'user', metadata: ['event' => 'EMPLOYEE_RESPONDED']);
            } elseif ($byRequester && $fresh->status === 'resolved') {
                $this->lifecycle->move($fresh, 'in_progress', $author, AuditAction::RequestStatusChanged, 'The employee replied after resolution', [], 'user', metadata: ['event' => 'REQUEST_REOPENED']);
            } elseif (! $byRequester && $visibility === 'employee') {
                $to = in_array($fresh->status, ['submitted', 'acknowledged', 'assigned'], true) ? 'in_progress' : null;
                $this->lifecycle->move($fresh, $to, $author, AuditAction::RequestStatusChanged, null, $fresh->first_responded_at === null ? ['first_responded_at' => now()] : [], 'user');
            }
            $this->audit->record(AuditAction::CommentCreated, 'servicedesk', $fresh, [], null, actor: $author, metadata: ['comment_id' => $comment->id, 'visibility' => $visibility]);
            if ($stored !== []) {
                $this->audit->record(AuditAction::AttachmentUploaded, 'servicedesk', $fresh, [], null, actor: $author, metadata: ['comment_id' => $comment->id, 'file' => $comment->attachment_name, 'sha256' => $comment->attachment_sha256, 'visibility' => $visibility]);
            }

            $employeeUser = $this->employeeUserId($fresh);
            $recipients = match (true) {
                $byRequester => [$fresh->assignee_id],
                $visibility === 'employee' => $fresh->visible_to_employee ? [$employeeUser, $fresh->raised_by] : [],
                default => [$fresh->assignee_id, $fresh->owner_id],
            };
            $recipients = array_values(array_diff(array_filter($recipients), [$author->id]));
            ServiceDeskEvent::dispatch('servicedesk.ticket.commented', $fresh, $this->lifecycle->context($fresh) + ['by' => $author->name, 'internal' => $visibility !== 'employee'], $recipients);

            $ticket->setRawAttributes($fresh->getAttributes(), true);

            return $comment;
        });
    }

    public function waitOnEmployee(Ticket $ticket, ?User $actor = null, ?int $expectedVersion = null): Ticket
    {
        $actor ??= $this->actor();
        $this->assertWorker($actor, $ticket);

        return $this->lifecycle->move($ticket, 'waiting_employee', $actor, AuditAction::RequestStatusChanged, null, [], 'user', $expectedVersion,
            notify: ['event' => 'servicedesk.ticket.waiting_for_employee', 'recipients' => $ticket->visible_to_employee ? [$this->employeeUserId($ticket)] : []], asWorker: true);
    }

    public function waitOnHr(Ticket $ticket, User $actor, ?string $reason = null, ?int $expectedVersion = null): Ticket
    {
        $this->assertWorker($actor, $ticket);

        return $this->lifecycle->move($ticket, 'waiting_hr', $actor, AuditAction::RequestStatusChanged, $reason, [], 'user', $expectedVersion, asWorker: true);
    }

    public function resolve(Ticket $ticket, string $resolution, ?User $actor = null, ?int $articleId = null, ?int $expectedVersion = null): Ticket
    {
        $actor ??= $this->actor();
        $this->assertWorker($actor, $ticket);
        $instance = null;
        $resolved = $this->lifecycle->move($ticket, 'resolved', $actor, AuditAction::RequestResolved, $resolution, [], 'user', $expectedVersion,
            guard: function (Ticket $fresh) use ($resolution, $articleId, &$instance) {
                if (in_array($fresh->domain_action_status, ['ready', 'awaiting_approval'], true)) {
                    // Resolved without executing the change: the pending change is withdrawn and its values purged.
                    $fresh->fill(['domain_action_status' => 'cancelled'] + $this->executor->purge($fresh));
                }
                $instance = $fresh->workflow_instance_id;
                $fresh->fill(['resolution' => $resolution, 'article_id' => $articleId ?? $fresh->article_id, 'first_responded_at' => $fresh->first_responded_at ?? now()]);
            },
            notify: ['event' => 'servicedesk.ticket.resolved', 'recipients' => $ticket->visible_to_employee ? [$this->employeeUserId($ticket), $ticket->raised_by] : [$ticket->raised_by]],
            asWorker: true,
        );
        $this->cancelWorkflow($instance, 'The request was resolved');

        return $resolved;
    }

    public function close(Ticket $ticket, ?User $actor = null, ?int $satisfaction = null, ?string $comment = null, ?int $expectedVersion = null): Ticket
    {
        if ($ticket->status === 'closed') {
            return $ticket;
        }
        if ($satisfaction !== null && ($satisfaction < 1 || $satisfaction > 5)) {
            throw new ServiceDeskRuleViolation('Satisfaction is rated 1 to 5.');
        }
        $system = $actor === null;
        if (! $system && ! $this->access->canWork($actor, $ticket) && ! $this->isRequester($actor, $ticket)) {
            throw new ServiceDeskRuleViolation('You cannot close this request.');
        }
        $rating = ! $system && $this->isRequester($actor, $ticket) ? array_filter(['satisfaction' => $satisfaction, 'satisfaction_comment' => $comment], fn ($v) => $v !== null) : [];

        return $this->lifecycle->move($ticket, 'closed', $actor, AuditAction::RequestClosed, $system ? 'Closed automatically after resolution' : null, $rating, $system ? 'system' : 'user', $expectedVersion,
            notify: ['event' => 'servicedesk.ticket.closed', 'recipients' => [$ticket->assignee_id]]);
    }

    public function reopen(Ticket $ticket, string $reason, ?User $actor = null, ?int $expectedVersion = null): Ticket
    {
        $actor ??= $this->actor();
        if (! $this->access->canWork($actor, $ticket) && ! $this->isRequester($actor, $ticket)) {
            throw new ServiceDeskRuleViolation('You cannot reopen this request.');
        }
        if (! in_array($ticket->status, ['resolved', 'closed'], true)) {
            throw new ServiceDeskRuleViolation('Only a resolved or closed ticket can be reopened.');
        }

        return $this->lifecycle->move($ticket, 'in_progress', $actor, AuditAction::RequestStatusChanged, $reason, [], 'user', $expectedVersion,
            notify: ['event' => 'servicedesk.ticket.reopened', 'recipients' => [$ticket->assignee_id]], metadata: ['event' => 'REQUEST_REOPENED']);
    }

    /** Withdraw a request (requester before resolution, or HR); a pending change is withdrawn and purged. */
    public function cancel(Ticket $ticket, User $actor, string $reason, ?int $expectedVersion = null): Ticket
    {
        if (! $this->access->canWork($actor, $ticket) && ! ($this->isRequester($actor, $ticket) || ((int) $ticket->raised_by === (int) $actor->id && $ticket->status === 'draft'))) {
            throw new ServiceDeskRuleViolation('You cannot cancel this request.');
        }
        $instance = null;
        $cancelled = $this->lifecycle->move($ticket, 'cancelled', $actor, AuditAction::RequestCancelled, $reason, [], 'user', $expectedVersion,
            guard: function (Ticket $fresh) use (&$instance) {
                if ($fresh->domain_action_status === 'executed' && $fresh->domain_action !== null && $this->executorTiming($fresh) !== 'on_submit') {
                    throw new ServiceDeskRuleViolation('The change was already applied; it cannot be cancelled here.');
                }
                if (in_array($fresh->domain_action_status, ['ready', 'awaiting_approval', 'refused'], true)) {
                    $fresh->fill(['domain_action_status' => 'cancelled']);
                }
                $fresh->fill($this->executor->purge($fresh));
                $instance = $fresh->workflow_instance_id;
            },
            notify: ['event' => 'servicedesk.ticket.cancelled', 'recipients' => array_values(array_diff(array_filter([$ticket->assignee_id, $this->employeeUserId($ticket)]), [$actor->id]))],
        );
        $this->cancelWorkflow($instance, 'The request was cancelled');

        return $cancelled;
    }

    /** Start the approval again after an approval was refused for separation of duties. */
    public function restartApproval(Ticket $ticket, User $actor): Ticket
    {
        $this->assertWorker($actor, $ticket);
        $version = $ticket->serviceVersion()->first();
        $workflow = $version?->workflow_key ? Workflow::query()->where('key', $version->workflow_key)->where('status', 'active')->first() : null;
        if ($workflow === null || ! $workflow->published()->exists()) {
            throw new ServiceDeskRuleViolation('This service has no approval workflow available.');
        }

        return DB::transaction(function () use ($ticket, $actor, $workflow) {
            $fresh = Ticket::query()->withoutGlobalScope(AccessScope::class)->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            if ($fresh->domain_action_status !== 'refused' || ! $fresh->isOpen()) {
                throw new ServiceDeskRuleViolation('Only a request whose approval was refused can restart approval.');
            }
            $instance = $this->workflows->start($workflow, $fresh, ['ticket' => ['number' => $fresh->number, 'employee_id' => $fresh->employee_id]], $actor);

            return $this->lifecycle->move($fresh, 'awaiting_approval', $actor, AuditAction::RequestStatusChanged, 'Approval restarted', ['workflow_instance_id' => $instance->id, 'domain_action_status' => 'awaiting_approval'], 'user');
        });
    }

    /** Explicit access to a restricted case (only someone with explicit access grants it, with a reason). */
    public function grantAccess(Ticket $ticket, User $user, string $reason, User $actor): TicketAccessGrant
    {
        if (! $ticket->isRestricted()) {
            throw new ServiceDeskRuleViolation('Only restricted cases use explicit access.');
        }
        if (! $this->access->hasExplicitAccess($actor, $ticket)) {
            throw new ServiceDeskRuleViolation('You do not have access to this case.');
        }
        if (blank($reason)) {
            throw new ServiceDeskRuleViolation('Granting access needs a reason.');
        }
        $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->findOrFail($ticket->employee_id);
        if (! $user->isActive() || ! $user->hasPermission('servicedesk.confidential') || ! app(AccessScopes::class)->allows($user, $employee) || (int) $employee->user_id === (int) $user->id) {
            throw new ServiceDeskRuleViolation($user->name.' cannot be given access (servicedesk.confidential, organisation scope, not the case subject).');
        }

        return DB::transaction(function () use ($ticket, $user, $reason, $actor) {
            Ticket::query()->withoutGlobalScope(AccessScope::class)->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $grant = TicketAccessGrant::query()->firstOrNew(['ticket_id' => $ticket->id, 'user_id' => $user->id]);
            $grant->fill(['granted_by' => $actor->id, 'reason' => $reason, 'revoked_at' => null, 'revoked_by' => null])->withAuditReason($reason)->save();
            $this->audit->record(AuditAction::PermissionChanged, 'servicedesk', $ticket, [['field' => 'access', 'before' => null, 'after' => 'user #'.$user->id]], $reason, actor: $actor, metadata: ['event' => 'CASE_ACCESS_GRANTED']);

            return $grant;
        });
    }

    public function revokeAccess(Ticket $ticket, User $user, string $reason, User $actor): void
    {
        if (! $this->access->hasExplicitAccess($actor, $ticket)) {
            throw new ServiceDeskRuleViolation('You do not have access to this case.');
        }
        DB::transaction(function () use ($ticket, $user, $reason, $actor) {
            // The request row first, as every other change to the case does (serialises with RequestLifecycle).
            Ticket::query()->withoutGlobalScope(AccessScope::class)->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $grant = TicketAccessGrant::query()->where('ticket_id', $ticket->id)->where('user_id', $user->id)->whereNull('revoked_at')->lockForUpdate()->first()
                ?? throw new ServiceDeskRuleViolation('That person has no explicit access.');
            $grant->withAuditReason($reason)->update(['revoked_at' => now(), 'revoked_by' => $actor->id]);
            $this->audit->record(AuditAction::PermissionChanged, 'servicedesk', $ticket, [['field' => 'access', 'before' => 'user #'.$user->id, 'after' => null]], $reason, actor: $actor, metadata: ['event' => 'CASE_ACCESS_REVOKED']);
        });
    }

    /** Escalate breached requests, remind, auto-close (legacy entry point; the scheduler runs ServiceDeskProcessor). */
    public function tick(): array
    {
        return app(ServiceDeskProcessor::class)->run();
    }

    /** Collision-free numbers for service desk records (TKT, GRV) — a locked sequence per tenant and year. */
    public function nextNumber(string $prefix): string
    {
        $model = $prefix === 'TKT' ? Ticket::class : Grievance::class;
        $seed = NumberSequences::highest($model);
        $this->numbers->ensure($prefix, $seed);

        return $this->numbers->next($prefix, $seed);
    }

    /** Temporary signed link to a comment's attachment; the download route re-authorises the ticket. */
    public function attachmentUrl(TicketComment $comment, int $minutes = 15): ?string
    {
        return $this->attachments->url($comment, $minutes);
    }

    private function assertWorker(User $actor, Ticket $ticket): void
    {
        if (! $this->access->canWork($actor, $ticket)) {
            throw new ServiceDeskRuleViolation('You cannot work this case.');
        }
    }

    private function isRequester(User $actor, Ticket $ticket): bool
    {
        return $this->access->isOwn($actor, $ticket) || (int) $ticket->raised_by === (int) $actor->id;
    }

    private function employeeUserId(Ticket $ticket): ?int
    {
        $id = Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($ticket->employee_id)->value('user_id');

        return $id === null ? null : (int) $id;
    }

    private function executorTiming(Ticket $ticket): ?string
    {
        return app(DomainActions::class)->find($ticket->domain_action)?->timing();
    }

    private function cancelWorkflow(?int $instanceId, string $reason): void
    {
        $instance = $instanceId ? WorkflowInstance::query()->find($instanceId) : null;
        if ($instance !== null && $instance->status->isOpen()) {
            $this->workflows->cancel($instance, $reason);
        }
    }

    private function actor(): User
    {
        return auth()->user() ?? throw new ServiceDeskRuleViolation('A person performs this step.');
    }
}
