<?php

namespace App\Domain\ServiceDesk\Listeners;

use App\Domain\Attendance\Events\AttendanceEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Exit\Events\ExitEvent;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Leave\Events\LeaveEvent;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Services\RequestLifecycle;
use Illuminate\Events\Dispatcher;
use Throwable;

/**
 * Phase 12: a request handed to its domain at submission (leave, attendance correction, letter) follows
 * the domain's decision. The listener reads only the domain's own events, never its tables, and finds
 * the request by its record reference:
 * - a decision resolves the request;
 * - a letter approval moves it back to work (HR issues the letter);
 * - an issued letter resolves it.
 */
final class ServiceDeskDomainListener
{
    private const OUTCOMES = [
        'leave.approved' => 'Leave approved.', 'leave.rejected' => 'Leave not approved.', 'leave.cancelled' => 'Leave cancelled.',
        'attendance.regularisation_approved' => 'Attendance correction approved.', 'attendance.regularisation_rejected' => 'Attendance correction not approved.',
        'attendance.regularisation_cancelled' => 'Attendance correction cancelled.', 'letter.issued' => 'Letter issued.',
    ];

    public function __construct(private readonly RequestLifecycle $lifecycle) {}

    public function subscribe(Dispatcher $events): array
    {
        return [LeaveEvent::class => 'handle', AttendanceEvent::class => 'handle', ExitEvent::class => 'handle'];
    }

    public function handle(LeaveEvent|AttendanceEvent|ExitEvent $event): void
    {
        if (! array_key_exists($event->name, self::OUTCOMES) && $event->name !== 'letter.approved') {
            return;
        }
        Ticket::query()->withoutGlobalScope(AccessScope::class)->where('domain_reference_type', $event->subject->getMorphClass())->where('domain_reference_id', $event->subject->getKey())
            ->whereIn('status', Ticket::OPEN)->orderBy('id')->get()
            ->each(function (Ticket $ticket) use ($event) {
                try {
                    if ($event->name === 'letter.approved') {
                        if ($ticket->status === 'awaiting_approval') {
                            $this->lifecycle->move($ticket, 'in_progress', null, AuditAction::RequestStatusChanged, 'Letter approved; waiting to be issued', [], 'system', metadata: ['event' => 'DOMAIN_APPROVED']);
                        }

                        return;
                    }
                    $this->lifecycle->move($ticket, 'resolved', null, AuditAction::RequestResolved, self::OUTCOMES[$event->name], ['resolution' => self::OUTCOMES[$event->name]], 'system',
                        notify: ['event' => 'servicedesk.ticket.resolved', 'recipients' => $ticket->visible_to_employee ? [$event->employee?->user_id] : []],
                        metadata: ['event' => 'DOMAIN_OUTCOME', 'domain_event' => $event->name]);
                } catch (Throwable $e) {
                    report($e); // the domain decision stands; the request can still be resolved by HR
                }
            });
    }
}
