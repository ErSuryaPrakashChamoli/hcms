<?php

namespace App\Filament\Auth;

use App\Domain\Identity\Services\PasswordResets;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;
use Filament\Notifications\Notification;
use Illuminate\Support\Timebox;

/**
 * SaaS.2: "Forgot password". The answer is the same, and takes the same time, whether or not the address
 * belongs to an account that may reset (no account enumeration). Rate-limited per IP by the page and per
 * address by the password broker.
 */
class RequestPasswordReset extends BaseRequestPasswordReset
{
    public const SENT = 'If an account uses that address, we have sent it a link to choose a new password.';

    public function request(): void
    {
        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        $email = (string) ($this->form->getState()['email'] ?? '');
        app(Timebox::class)->call(fn () => app(PasswordResets::class)->request($email), (int) config('auth.timebox_duration', 200_000));

        Notification::make()->success()->title(self::SENT)->body('The link expires in '.(int) config('auth.passwords.users.expire', 60).' minutes.')->send();
        $this->form->fill();
    }
}
