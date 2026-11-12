<?php

namespace App\Support\Observability;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * On production, mail never goes to a reserved, undeliverable domain (.local, .test, example.*: PlatformReadiness). Only
 * fictional accounts use them (the demo organisation, demo.local), and sending to them would only bounce off the real
 * mail provider and hurt the sender's reputation. Those recipients are taken off the message; a message left with no
 * recipient is not sent (Laravel skips a message when a MessageSending listener returns false). Counts are logged,
 * never addresses. Other environments are untouched (their mailer is log or array).
 */
final class SuppressUndeliverableMail
{
    public function handle(MessageSending $event): ?bool
    {
        if (! app()->isProduction() || ! $event->message instanceof Email) {
            return null;
        }
        $message = $event->message;
        $headers = $message->getHeaders();
        $suppressed = 0;
        $remaining = 0;
        foreach (['To' => $message->getTo(), 'Cc' => $message->getCc(), 'Bcc' => $message->getBcc()] as $field => $addresses) {
            $kept = array_values(array_filter($addresses, fn (Address $address) => ! PlatformReadiness::isReservedEmail($address->getAddress())));
            $remaining += count($kept);
            if (count($kept) === count($addresses)) {
                continue;
            }
            $suppressed += count($addresses) - count($kept);
            $headers->remove($field);
            if ($kept !== []) {
                $headers->addMailboxListHeader($field, $kept);
            }
        }
        if ($suppressed === 0) {
            return null;
        }
        Log::info('Mail to reserved, undeliverable addresses suppressed.', ['suppressed' => $suppressed, 'remaining' => $remaining]);

        return $remaining > 0 ? null : false;
    }
}
