<?php

namespace App\Domain\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * SaaS.2: the forgotten-password e-mail. Sent directly (after the response), never queued: a queued
 * notification would store the reset link, token included, in the jobs table.
 */
class PasswordResetLink extends Notification
{
    use Queueable;

    public function __construct(#[\SensitiveParameter] private readonly string $url, private readonly int $expiresInMinutes) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Reset your PeopleOS password')
            ->line('We received a request to reset the password for your PeopleOS account.')
            ->action('Choose a new password', $this->url)
            ->line("This link expires in {$this->expiresInMinutes} minutes and can be used once.")
            ->line('If you did not ask for this, you can ignore this e-mail; your password has not changed.');
    }
}
