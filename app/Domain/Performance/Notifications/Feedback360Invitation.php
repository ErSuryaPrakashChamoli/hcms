<?php

namespace App\Domain\Performance\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * HROne setup parity HP-8h (PF-4): the e-mail asking a client for 360 feedback about an employee, with a signed link that
 * works once and expires when the round's window closes. It carries names and the date only.
 */
class Feedback360Invitation extends Notification
{
    public function __construct(public readonly string $clientName, public readonly string $about, public readonly string $due, public readonly string $url) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your feedback is requested'.($this->about !== '' ? " about {$this->about}" : ''))
            ->greeting('Dear '.($this->clientName !== '' ? $this->clientName : 'colleague').',')
            ->line('You have been asked for feedback'.($this->about !== '' ? " about {$this->about}" : '').'. It takes a few minutes and needs no account.')
            ->action('Give feedback', $this->url)
            ->line("The link works once and expires on {$this->due}.");
    }
}
