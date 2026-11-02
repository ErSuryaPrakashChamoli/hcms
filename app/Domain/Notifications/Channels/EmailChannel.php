<?php

namespace App\Domain\Notifications\Channels;

use App\Domain\Notifications\Contracts\MailEnvelope;
use App\Domain\Notifications\Mail\NotificationMail;
use App\Domain\Notifications\Models\NotificationDelivery;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Mail;

final class EmailChannel implements Channel
{
    public function send(NotificationDelivery $delivery): void
    {
        $address = $delivery->recipient ?: $delivery->user?->email ?? throw new \RuntimeException('No email address for delivery.');

        // HROne parity WO-13: a source that shapes its e-mail (more recipients, copies, attachments); none = as before.
        $class = $delivery->source_type !== null ? (Relation::getMorphedModel($delivery->source_type) ?? $delivery->source_type) : null;
        $envelope = $class !== null && is_subclass_of($class, MailEnvelope::class) ? $delivery->source : null;
        if (! $envelope instanceof MailEnvelope) {
            // HROne parity WO-4: events listed as plain text go without the layout (no link to the application); none = as before.
            $plain = in_array($delivery->event, (array) config('peopleos.notifications.plain_text_events', []), true);
            Mail::to($address)->send(new NotificationMail($delivery->subject, $delivery->body, plainText: $plain));

            return;
        }
        $addresses = $envelope->mailAddresses($delivery);
        Mail::to([$address, ...($addresses['to'] ?? [])])
            ->cc($addresses['cc'] ?? [])
            ->bcc($addresses['bcc'] ?? [])
            ->send(new NotificationMail($delivery->subject, $delivery->body, $envelope->mailAttachments($delivery)));
    }
}
