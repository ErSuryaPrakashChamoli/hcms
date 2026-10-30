<?php

namespace App\Domain\Workflow\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * HROne setup parity P3 (Inbox actions): workflow.task.sent_back (an approver sent a request back to its requester),
 * workflow.task.resubmitted (the requester answered and sent it again) and workflow.task.commented (a new message in a
 * request's discussion). `subject` is the request (or the workflow run) the message is about, `source` the record the
 * notification links to (the send-back or the comment). Context holds labels and names only, never the message text.
 * Recipients get an in-app note when no notification rule sends one.
 */
final class InboxEvent
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $context
     * @param  list<int>  $recipientUserIds
     */
    public function __construct(
        public readonly string $name,
        public readonly Model $subject,
        public readonly Model $source,
        public readonly array $context = [],
        public readonly array $recipientUserIds = [],
    ) {}
}
