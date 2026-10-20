<?php

namespace App\Domain\Signup\Support;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Signup\Models\SignupApplication;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * SaaS.10: signup e-mails go out now, never through the queue (a link never sits in the jobs table), and every attempt
 * is audited with what is actually known: the mail transport accepted the message (SIGNUP_EMAIL_SENT) or refused it
 * (SIGNUP_EMAIL_FAILED). Acceptance is not delivery, and a local `log` mailer delivers nothing at all. A failure is
 * logged by kind and exception class only (its message can carry the address or a link), and never thrown at the
 * person who asked: the page answers the same either way.
 */
final class SignupMailer
{
    public function __construct(private readonly SignupAudit $signupAudit, private readonly AuditRecorder $audit) {}

    public function send(string $email, BaseNotification $notification, string $kind, ?SignupApplication $application = null, ?User $actor = null): bool
    {
        try {
            Notification::route('mail', $email)->notify($notification);
            $accepted = true;
        } catch (Throwable $e) {
            Log::warning('A signup e-mail was not accepted by the mail transport.', ['kind' => $kind, 'reference' => $application?->reference, 'error' => class_basename($e)]);
            $accepted = false;
        }

        $action = $accepted ? AuditAction::SignupEmailSent : AuditAction::SignupEmailFailed;
        $metadata = ['kind' => $kind, 'mailer' => (string) config('mail.default'), 'meaning' => $accepted ? 'accepted by the mail transport (not proof of delivery)' : 'refused by the mail transport'];
        if ($application !== null) {
            $this->signupAudit->record($action, $application, $application->status?->value, $actor, null, $metadata);
        } else {
            // No application (an address that already has an account): recorded without anything that identifies it.
            $this->audit->record($action, 'signup', null, [], null, entityLabel: 'Signup', metadata: $metadata, anonymous: ! app()->runningInConsole(), platform: true);
        }

        return $accepted;
    }
}
