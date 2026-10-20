<?php

namespace App\Domain\Identity\Listeners;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\MultiFactor;
use App\Domain\Identity\Services\SessionSecurity;
use App\Http\Middleware\EnforceAccountSecurity;
use Illuminate\Auth\Events\Login;

/**
 * SaaS.2: every login (password, remember-me cookie or SSO) starts a session that is stamped with the
 * current session epochs and that has not yet proved multi-factor authentication. The password login
 * marks the proof after its challenge; any other path is sent to the challenge page.
 */
final class SecureNewSession
{
    public function __construct(private readonly SessionSecurity $sessions, private readonly MultiFactor $mfa) {}

    public function handle(Login $event): void
    {
        if (! $event->user instanceof User || ! request()->hasSession()) {
            return;
        }
        $session = request()->session();
        $this->sessions->stamp($session, $event->user);
        $this->mfa->forget($session);
        $session->forget(EnforceAccountSecurity::AUTH_METHOD_KEY);
    }
}
