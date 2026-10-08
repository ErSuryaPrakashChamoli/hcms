<?php

namespace App\Filament\Auth;

use App\Domain\Identity\Services\PasswordResets;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\PasswordResetResponse;
use Filament\Auth\Pages\PasswordReset\ResetPassword as BaseResetPassword;
use Filament\Notifications\Notification;

/**
 * SaaS.2: choosing a new password from a reset link. Any invalid link (unknown address, wrong, used or
 * expired token, account that may not sign in) gets the same message. The tenant password policy applies,
 * every existing session ends, and multi-factor authentication is unchanged.
 */
class ResetPassword extends BaseResetPassword
{
    public function resetPassword(): ?PasswordResetResponse
    {
        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }
        if ($this->isResetPasswordRateLimited($this->email)) {
            return null;
        }

        $data = $this->form->getState();
        $done = app(PasswordResets::class)->reset((string) $this->email, (string) $this->token, (string) $data['password']);

        if (! $done) {
            Notification::make()->danger()->title(PasswordResets::INVALID)->send();

            return null;
        }

        Notification::make()->success()->title('Your password has been changed. Sign in with it now.')->send();

        return app(PasswordResetResponse::class);
    }
}
