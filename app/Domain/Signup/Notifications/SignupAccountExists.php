<?php

namespace App\Domain\Signup\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * SaaS.10: sent instead of a registration link when the address already signs in to PeopleOS (D-17: one login per
 * address). The page never says so; only the owner of the mailbox learns it.
 */
class SignupAccountExists extends Notification
{
    public function __construct(private readonly string $loginUrl, private readonly string $resetUrl) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('You already have a PeopleOS account')
            ->line('Someone asked to register an organisation on PeopleOS with this e-mail address, which already has a PeopleOS account.')
            ->action('Sign in', $this->loginUrl)
            ->line('Forgotten your password? Reset it here: '.$this->resetUrl)
            ->line('One e-mail address signs in to one organisation. To register another organisation, use a different address.')
            ->line('If this was not you, ignore this e-mail: nothing has changed.');
    }
}
