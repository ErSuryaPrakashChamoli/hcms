<?php

namespace App\Domain\Notifications\Contracts;

use App\Domain\Notifications\Models\NotificationDelivery;

/**
 * HROne parity WO-13: a delivery's source may shape its e-mail beyond the one recipient the delivery log keeps: more
 * recipients, copies (CC / BCC) and attachments (e.g. an e-mailed letter's file). The e-mail channel asks the delivery's
 * source when it implements this; every other e-mail is sent exactly as before. Read when the mail is sent (also on a
 * resend), so a source may refuse by throwing — the delivery then fails like any other send error.
 */
interface MailEnvelope
{
    /**
     * Addresses besides the delivery's own recipient.
     *
     * @return array{to: list<string>, cc: list<string>, bcc: list<string>}
     */
    public function mailAddresses(NotificationDelivery $delivery): array;

    /**
     * Files to attach.
     *
     * @return list<array{content: string, name: string, mime: string}>
     */
    public function mailAttachments(NotificationDelivery $delivery): array;
}
