<?php

namespace App\Domain\Experience\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * HROne parity RQ-2 (request chrome): a requester following up their own request.
 *
 * - request.reminder: "Send reminder" — to the people the request waits with (the pending workflow tasks' assignees, else
 *   the people who have it in their Approval Center). Context: the request's labels, who reminded, never its reason.
 * - request.intimated: "Notify others" — to the colleagues the requester chose, with the requester's message (at most
 *   500 characters; the one thing the requester meant to send them). Context: the request's type and title only.
 *
 * Notification rules decide first (audience `named` = the recipients, `assignee` = the approvers of a reminder); when no
 * rule sends anything, the recipients get an in-app note (NotificationEventBridge::onRequestFollowUp).
 */
final class RequestFollowUpEvent
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $context
     * @param  list<int>  $recipientUserIds
     */
    public function __construct(
        public readonly string $name,
        public readonly Model $request,
        public readonly array $context = [],
        public readonly array $recipientUserIds = [],
    ) {}
}
