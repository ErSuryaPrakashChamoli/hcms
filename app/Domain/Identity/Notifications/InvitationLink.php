<?php

namespace App\Domain\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/** SaaS.2: the invitation e-mail. Sent directly, never queued, so the token never sits in the jobs table. */
class InvitationLink extends Notification
{
    use Queueable;

    public function __construct(
        #[\SensitiveParameter] private readonly string $url,
        private readonly string $organisation,
        private readonly Carbon $expiresAt,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("You have been invited to {$this->organisation} on PeopleOS")
            ->line("{$this->organisation} has invited you to PeopleOS.")
            ->action('Accept the invitation', $this->url)
            ->line('The link works once and expires on '.$this->expiresAt->toDayDateTimeString().' (UTC).')
            ->line('If you were not expecting this invitation, you can ignore this e-mail.');
    }
}
