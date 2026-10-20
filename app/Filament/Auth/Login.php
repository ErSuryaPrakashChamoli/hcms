<?php

namespace App\Filament\Auth;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\MultiFactor;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;

/**
 * SaaS.2: Filament's password sign-in, which challenges an authenticator before signing in. The proof is
 * remembered for this session only; every other way into a session (remember-me cookie, SSO) is challenged
 * again by EnforceAccountSecurity.
 */
class Login extends BaseLogin
{
    public function authenticate(): ?LoginResponse
    {
        $response = parent::authenticate();
        $user = Filament::auth()->user();

        // A response is only returned after the challenge was passed, for a user who has an authenticator.
        if ($response !== null && $user instanceof User && $user->hasMfaEnabled()) {
            app(MultiFactor::class)->markVerified(session()->driver(), $user);
        }

        return $response;
    }
}
