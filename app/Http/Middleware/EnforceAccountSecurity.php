<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\MultiFactor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SaaS.2: account security that must be complete before anything else in the workspace, enforced on the
 * server for every page, every Livewire call and every protected download (never a hidden screen).
 *
 * 1. A user with an authenticator must have proved it in this session. Password sign-in proves it at the
 *    login challenge; a remember-me or SSO session proves it on the challenge page.
 * 2. A user who must use MFA (tenant policy, or the platform policy for operators) and has none is sent to
 *    set one up.
 * 3. A user whose e-mail address is not verified is sent to verify it, unless the session was opened
 *    through SSO (the identity provider vouches for the address).
 *
 * Only the pages that complete these steps, and sign-out, stay reachable meanwhile.
 */
class EnforceAccountSecurity
{
    /** @var list<string> */
    public const OPEN_ROUTES = [
        'filament.admin.auth.logout',
        'filament.admin.auth.multi-factor-authentication.set-up-required',
        'filament.admin.auth.multi-factor-authentication.challenge',
        'filament.admin.auth.email-verification.prompt',
        'filament.admin.auth.email-verification.verify',
    ];

    public const AUTH_METHOD_KEY = 'auth.method';

    public function __construct(private readonly MultiFactor $mfa) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || ! $request->hasSession() || $request->routeIs(...self::OPEN_ROUTES)) {
            return $next($request);
        }
        $session = $request->session();

        $target = match (true) {
            $user->hasMfaEnabled() && ! $this->mfa->isVerified($session, $user) => 'filament.admin.auth.multi-factor-authentication.challenge',
            ! $user->hasMfaEnabled() && $this->mfa->requiredFor($user) => 'filament.admin.auth.multi-factor-authentication.set-up-required',
            ! $user->hasVerifiedEmail() && $session->get(self::AUTH_METHOD_KEY) !== 'sso' => 'filament.admin.auth.email-verification.prompt',
            default => null,
        };
        if ($target === null) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Complete your account security before continuing.'], 403);
        }
        // Inside a Livewire call this request is the page's own request, so the page is remembered, never the update endpoint.
        if ($request->isMethod('GET')) {
            $session->put('url.intended', $request->fullUrl());
        }

        return redirect()->route($target);
    }
}
