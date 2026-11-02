<?php

namespace App\Domain\Letters\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Domain\Letters\Services\Letters;
use App\Domain\Notifications\Contracts\MailEnvelope;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * HROne parity WO-13 (§23.7.1 #14, §23.7.2 #8): an issued letter e-mailed to the employee. The e-mail is one notification
 * delivery (the delivery log, the tenant's mail transport, retries and resend as for every e-mail); this row is its
 * envelope: the other recipients, the copies and the attachment (the letter's own file, read when the mail is sent — a
 * letter revoked meanwhile is not sent). The body is never kept here; the send is audited on the letter (LETTER_EMAILED)
 * with the kinds of address and the number of copies, so this row is written without automatic auditing.
 */
#[Fillable(['tenant_id', 'letter_id', 'sent_by', 'to_kinds', 'recipients', 'cc', 'bcc', 'subject', 'notification_delivery_id'])]
class LetterEmail extends Model implements MailEnvelope
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return ['to_kinds' => 'array', 'recipients' => 'array', 'cc' => 'array', 'bcc' => 'array'];
    }

    public function auditModule(): string
    {
        return 'letters';
    }

    public function auditSensitiveAttributes(): array
    {
        return [...config('peopleos.audit.sensitive_attributes', []), 'recipients', 'cc', 'bcc'];
    }

    public function letter(): BelongsTo
    {
        return $this->belongsTo(Letter::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(NotificationDelivery::class, 'notification_delivery_id');
    }

    public function mailAddresses(NotificationDelivery $delivery): array
    {
        $primary = mb_strtolower((string) $delivery->recipient);

        return [
            'to' => array_values(array_filter((array) $this->recipients, fn ($a) => mb_strtolower((string) $a) !== $primary)),
            'cc' => array_values((array) ($this->cc ?? [])),
            'bcc' => array_values((array) ($this->bcc ?? [])),
        ];
    }

    public function mailAttachments(NotificationDelivery $delivery): array
    {
        $letter = $this->letter()->first();
        if ($letter === null || $letter->status !== 'issued') {
            throw new RuntimeException('The letter is no longer issued; it is not sent.');
        }
        $file = app(Letters::class)->file($letter);

        return [['content' => $file['content'], 'name' => $file['name'], 'mime' => $file['mime']]];
    }
}
