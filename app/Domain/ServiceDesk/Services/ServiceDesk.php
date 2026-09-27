<?php

namespace App\Domain\ServiceDesk\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Grievance\Models\Grievance;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\ServiceDesk\Events\ServiceDeskEvent;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Models\TicketCategory;
use App\Domain\ServiceDesk\Models\TicketComment;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Services\WorkflowEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/** The HR service desk (§48): tickets with SLA clocks, assignment, conversation, escalation, resolution, satisfaction. */
final class ServiceDesk
{
    public function __construct(private readonly AuditRecorder $audit, private readonly WorkflowEngine $workflows) {}

    public function open(Employee $employee, TicketCategory $category, string $subject, string $description, string $priority = 'normal', ?User $raiser = null): Ticket
    {
        if ($category->status->value !== 'active') {
            throw new RuntimeException('That request type is not available.');
        }

        return DB::transaction(function () use ($employee, $category, $subject, $description, $priority, $raiser) {
            $ticket = Ticket::create([
                'number' => $this->nextNumber('TKT'),
                'ticket_category_id' => $category->id,
                'employee_id' => $employee->id,
                'raised_by' => $raiser?->id ?? auth()->id(),
                'subject' => $subject,
                'description' => $description,
                'priority' => $priority,
                'status' => 'new',
                'assignee_id' => $this->pickAssignee($category),
                'first_response_due_at' => now()->addHours($category->first_response_hours),
                'due_at' => now()->addHours($this->slaHours($category, $priority)),
            ]);

            if ($ticket->assignee_id) {
                $ticket->update(['status' => 'open']);
            }

            $this->audit->record(AuditAction::Create, 'servicedesk', $ticket, [], null, actor: $raiser, metadata: ['category' => $category->code, 'priority' => $priority]);
            ServiceDeskEvent::dispatch('servicedesk.ticket.created', $ticket, ['number' => $ticket->number, 'subject' => $subject, 'category' => $category->name, 'employee_id' => $employee->id], array_filter([$ticket->assignee_id]));

            if ($category->workflow_key) {
                $workflow = Workflow::query()->where('key', $category->workflow_key)->where('status', 'active')->first();
                if ($workflow && $workflow->published()->exists()) {
                    $instance = $this->workflows->start($workflow, $ticket, ['ticket' => ['number' => $ticket->number, 'subject' => $subject, 'category' => $category->code, 'employee_id' => $employee->id]], $raiser);
                    $ticket->update(['workflow_instance_id' => $instance->id]);
                }
            }

            return $ticket->refresh();
        });
    }

    /** Category default, else the least-loaded active holder of the category's role. */
    private function pickAssignee(TicketCategory $category): ?int
    {
        if ($category->default_assignee_id) {
            return $category->default_assignee_id;
        }
        if (! $category->assignee_role_id) {
            return null;
        }
        $candidates = Role::query()->find($category->assignee_role_id)?->users()->get()->filter(fn (User $u) => $u->isActive()) ?? collect();
        if ($candidates->isEmpty()) {
            return null;
        }

        return $candidates->sortBy(fn (User $u) => Ticket::query()->where('assignee_id', $u->id)->whereIn('status', Ticket::OPEN)->count())->first()->id;
    }

    private function slaHours(TicketCategory $category, string $priority): int
    {
        return match ($priority) {
            'urgent' => max(4, intdiv($category->sla_hours, 4)), 'high' => max(8, intdiv($category->sla_hours, 2)), 'low' => $category->sla_hours * 2, default => $category->sla_hours
        };
    }

    public function assign(Ticket $ticket, User $assignee, ?User $actor = null): Ticket
    {
        if (! $ticket->isOpen()) {
            throw new RuntimeException('The ticket is closed.');
        }
        $before = $ticket->assignee_id;
        $ticket->update(['assignee_id' => $assignee->id, 'status' => $ticket->status === 'new' ? 'open' : $ticket->status]);
        $this->audit->record(AuditAction::Delegated, 'servicedesk', $ticket, [['field' => 'assignee_id', 'before' => $before, 'after' => $assignee->id]], null, actor: $actor);
        ServiceDeskEvent::dispatch('servicedesk.ticket.assigned', $ticket, ['number' => $ticket->number, 'subject' => $ticket->subject], [$assignee->id]);

        return $ticket;
    }

    public function comment(Ticket $ticket, User $author, string $body, bool $internal = false, ?string $attachmentPath = null, ?string $attachmentName = null): TicketComment
    {
        if ($ticket->status === 'closed') {
            throw new RuntimeException('The ticket is closed; reopen it to continue the conversation.');
        }
        $byEmployee = $ticket->employee()->value('user_id') === $author->id && ! $internal;

        return DB::transaction(function () use ($ticket, $author, $body, $internal, $attachmentPath, $attachmentName, $byEmployee) {
            $comment = TicketComment::create(['ticket_id' => $ticket->id, 'author_id' => $author->id, 'body' => $body, 'is_internal' => $internal, 'attachment_path' => $attachmentPath, 'attachment_name' => $attachmentName]);

            $updates = [];
            if ($byEmployee) {
                if ($ticket->status === 'resolved') {
                    $updates['status'] = 'open';
                    $updates['resolved_at'] = null;
                } elseif ($ticket->status === 'pending') {
                    $updates['status'] = 'open';
                }
            } elseif (! $internal) {
                $updates['first_responded_at'] = $ticket->first_responded_at ?? now();
                if ($ticket->status === 'new') {
                    $updates['status'] = 'open';
                }
            }
            if ($updates) {
                $ticket->update($updates);
            }

            $recipients = $internal ? array_filter([$ticket->assignee_id !== $author->id ? $ticket->assignee_id : null]) : ($byEmployee ? array_filter([$ticket->assignee_id]) : array_filter([$ticket->employee()->value('user_id')]));
            ServiceDeskEvent::dispatch('servicedesk.ticket.commented', $ticket, ['number' => $ticket->number, 'subject' => $ticket->subject, 'by' => $author->name, 'internal' => $internal], $recipients);

            return $comment;
        });
    }

    public function waitOnEmployee(Ticket $ticket, ?User $actor = null): Ticket
    {
        if (! in_array($ticket->status, ['new', 'open'], true)) {
            throw new RuntimeException('Only an open ticket can wait on the employee.');
        }
        $ticket->update(['status' => 'pending']);

        return $ticket;
    }

    public function resolve(Ticket $ticket, string $resolution, ?User $actor = null, ?int $articleId = null): Ticket
    {
        if (! $ticket->isOpen()) {
            throw new RuntimeException('The ticket is not open.');
        }
        $ticket->update(['status' => 'resolved', 'resolution' => $resolution, 'resolved_at' => now(), 'article_id' => $articleId ?? $ticket->article_id, 'first_responded_at' => $ticket->first_responded_at ?? now()]);
        $this->audit->record(AuditAction::Update, 'servicedesk', $ticket, [['field' => 'status', 'before' => 'open', 'after' => 'resolved']], $resolution, actor: $actor);
        ServiceDeskEvent::dispatch('servicedesk.ticket.resolved', $ticket, ['number' => $ticket->number, 'subject' => $ticket->subject, 'resolution' => $resolution], array_filter([$ticket->employee()->value('user_id')]));

        return $ticket;
    }

    public function close(Ticket $ticket, ?User $actor = null, ?int $satisfaction = null, ?string $comment = null): Ticket
    {
        if ($ticket->status === 'closed') {
            return $ticket;
        }
        if ($satisfaction !== null && ($satisfaction < 1 || $satisfaction > 5)) {
            throw new RuntimeException('Satisfaction is rated 1 to 5.');
        }
        $ticket->update(['status' => 'closed', 'closed_at' => now(), 'resolved_at' => $ticket->resolved_at ?? now(), 'satisfaction' => $satisfaction ?? $ticket->satisfaction, 'satisfaction_comment' => $comment ?? $ticket->satisfaction_comment]);
        ServiceDeskEvent::dispatch('servicedesk.ticket.closed', $ticket, ['number' => $ticket->number, 'subject' => $ticket->subject, 'satisfaction' => $satisfaction], array_filter([$ticket->assignee_id]));

        return $ticket;
    }

    public function reopen(Ticket $ticket, string $reason, ?User $actor = null): Ticket
    {
        if (! in_array($ticket->status, ['resolved', 'closed'], true)) {
            throw new RuntimeException('Only a resolved or closed ticket can be reopened.');
        }
        $ticket->withAuditReason($reason)->update(['status' => 'open', 'resolved_at' => null, 'closed_at' => null, 'due_at' => now()->addHours($ticket->category()->value('sla_hours') ?? 48)]);
        ServiceDeskEvent::dispatch('servicedesk.ticket.reopened', $ticket, ['number' => $ticket->number, 'subject' => $ticket->subject, 'reason' => $reason], array_filter([$ticket->assignee_id]));

        return $ticket;
    }

    /** Escalate breached tickets once; auto-close resolved tickets the employee did not reopen. */
    public function tick(): array
    {
        $result = ['escalated' => 0, 'auto_closed' => 0];

        Ticket::query()->with('category.escalationRole')->whereIn('status', Ticket::OPEN)->whereNull('escalated_at')->where('due_at', '<', now())->get()
            ->each(function (Ticket $ticket) use (&$result) {
                $ticket->update(['escalated_at' => now()]);
                $recipients = $ticket->category->escalationRole?->users()->get()->filter(fn (User $u) => $u->isActive())->pluck('id')->all() ?? [];
                $this->audit->record(AuditAction::Escalated, 'servicedesk', $ticket, [], 'SLA breached', metadata: ['due_at' => $ticket->due_at->toDateTimeString()]);
                ServiceDeskEvent::dispatch('servicedesk.ticket.escalated', $ticket, ['number' => $ticket->number, 'subject' => $ticket->subject, 'due_at' => $ticket->due_at->toDateTimeString()], array_values(array_unique(array_filter([...$recipients, $ticket->assignee_id]))));
                $result['escalated']++;
            });

        $days = (int) config('peopleos.servicedesk.auto_close_days', 5);
        Ticket::query()->where('status', 'resolved')->where('resolved_at', '<', now()->subDays($days))->get()
            ->each(function (Ticket $ticket) use (&$result) {
                $this->close($ticket);
                $result['auto_closed']++;
            });

        return $result;
    }

    public function nextNumber(string $prefix): string
    {
        $year = now()->format('Y');
        $model = $prefix === 'TKT' ? Ticket::class : Grievance::class;
        $last = $model::query()->where('number', 'like', "{$prefix}-{$year}-%")->orderByDesc('id')->value('number');
        $seq = $last ? ((int) substr($last, -5)) + 1 : 1;

        return sprintf('%s-%s-%05d', $prefix, $year, $seq);
    }

    /** Temporary signed link to a comment's attachment; the download route re-authorises the ticket. */
    public function attachmentUrl(TicketComment $comment, int $minutes = 15): ?string
    {
        if ($comment->attachment_path === null) {
            return null;
        }

        return URL::temporarySignedRoute('tickets.attachment', now()->addMinutes($minutes), ['ticket' => $comment->ticket_id, 'comment' => $comment->id]);
    }
}
