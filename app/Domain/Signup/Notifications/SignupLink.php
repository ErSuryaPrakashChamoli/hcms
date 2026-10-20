<?php

namespace App\Domain\Signup\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use SensitiveParameter;

/** SaaS.10: the link that confirms the address (or continues an application). Sent directly, never queued. */
class SignupLink extends Notification
{
    public function __construct(#[SensitiveParameter] private readonly string $url, private readonly Carbon $expiresAt, private readonly bool $continuing) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->continuing ? 'Continue your PeopleOS registration' : 'Confirm your e-mail address for PeopleOS')
            ->line($this->continuing
                ? 'Someone asked to continue a PeopleOS registration with this e-mail address.'
                : 'Someone asked to register an organisation on PeopleOS with this e-mail address.')
            ->action($this->continuing ? 'Continue the registration' : 'Confirm and continue', $this->url)
            ->line('The link works once and expires on '.$this->expiresAt->toDayDateTimeString().' (UTC).')
            ->line('If this was not you, ignore this e-mail: nothing happens without the link.');
    }
}
