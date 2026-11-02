<?php

namespace App\Domain\Notifications\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array{content: string, name: string, mime: string}>  $files  HROne parity WO-13: attached files (none by default)
     * @param  bool  $plainText  HROne parity WO-4: a plain-text mail without the layout (whose header links to the application) —
     *                           for people who have no access yet (peopleos.notifications.plain_text_events)
     */
    public function __construct(public readonly string $subjectLine, public readonly string $bodyText, public readonly array $files = [], public readonly bool $plainText = false) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        if ($this->plainText) {
            return new Content(text: 'mail.notification-text', with: ['bodyText' => $this->bodyText]);
        }

        return new Content(markdown: 'mail.notification', with: ['bodyText' => $this->bodyText]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return array_map(fn (array $file) => Attachment::fromData(fn () => $file['content'], $file['name'])->withMime($file['mime']), $this->files);
    }
}
