<?php

namespace App\Domain\Signup\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use InvalidArgumentException;

/**
 * SaaS.10: what happened to an application (in review, being set up, not approved, set-up delayed). It names the
 * application by reference and never carries a link that would act on it; operator reasons are not copied into it.
 */
class SignupUpdate extends Notification
{
    public const KINDS = ['review', 'setting_up', 'rejected', 'delayed'];

    public function __construct(private readonly string $kind, private readonly string $reference, private readonly string $company)
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException("Unknown signup update [{$kind}].");
        }
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->line("Registration {$this->reference} for {$this->company}.");

        return match ($this->kind) {
            'review' => $mail->subject('Your PeopleOS registration is being reviewed')
                ->line('Markedge reviews each registration before setting up the organisation. You will receive an e-mail when it is decided.'),
            'setting_up' => $mail->subject('Your PeopleOS organisation is being set up')
                ->line('Your registration is approved. When your organisation is ready you will receive an e-mail to choose your password.'),
            'rejected' => $mail->subject('Your PeopleOS registration was not approved')
                ->line('Markedge could not approve this registration. If you think this is a mistake, contact Markedge and quote the reference above.'),
            'delayed' => $mail->subject('Your PeopleOS organisation needs a little longer')
                ->line('Setting up your organisation is taking longer than expected. Markedge has been notified and will contact you; you do not need to register again.'),
        };
    }
}
