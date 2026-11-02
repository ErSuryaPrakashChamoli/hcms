<?php

namespace App\Domain\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * The temporary password e-mail (TemporaryPasswords): the invitation for a new user, or a fresh start for an existing one.
 * Sent directly, never queued, so the password never sits in the jobs table.
 */
class TemporaryPasswordMail extends Notification
{
    use Queueable;

    public function __construct(
        #[\SensitiveParameter] private readonly string $password,
        private readonly string $organisation,
        private readonly Carbon $expiresAt,
        private readonly string $signInUrl,
        private readonly bool $invitation,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->invitation ? "You have been invited to {$this->organisation} on PeopleOS" : "Your temporary PeopleOS password for {$this->organisation}")
            ->line($this->invitation ? "{$this->organisation} has invited you to PeopleOS." : "{$this->organisation} has given you a temporary password for PeopleOS. Your previous password no longer works.")
            ->line('E-mail address: '.$notifiable->email)
            ->line('Temporary password: '.$this->password)
            ->action('Sign in', $this->signInUrl)
            ->line('When you sign in you choose your own password. The temporary password works until '.$this->expiresAt->toDayDateTimeString().' (UTC).')
            ->line($this->invitation ? 'If you were not expecting this invitation, you can ignore this e-mail.' : 'If you did not ask for this, tell your administrator.');
    }
}
