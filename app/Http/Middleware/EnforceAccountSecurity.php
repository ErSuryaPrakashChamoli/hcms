<?php

namespace App\Http\Middleware;

use App\Domain\Enterprise\Services\SecurityPolicy;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\MultiFactor;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
 * 4. HROne setup parity: a tenant user whose password is older than the tenant's expiry (security.password_expiry_days)
 *    chooses a new one before going on, unless the session was opened through SSO (no password was used). A password
 *    with no known change date counts as expired. Platform operators follow the platform's rules, not a tenant's.
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
        'filament.admin.auth.password-expired',
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
            self::passwordExpiredFor($user, $session) => 'filament.admin.auth.password-expired',
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

    /** Whether this session must choose a new password first (read from the raw attributes: strict mode, fresh instances). */
    public static function passwordExpiredFor(User $user, Session $session): bool
    {
        if ($user->tenant_id === null || $user->isPlatformAdmin() || $session->get(self::AUTH_METHOD_KEY) === 'sso') {
            return false;
        }
        $changed = $user->getAttributes()['password_changed_at'] ?? null;
        $expired = fn (): bool => app(SecurityPolicy::class)->passwordExpired($changed === null ? null : Carbon::parse($changed));
        $tenants = app(TenantContext::class);
        if ($tenants->id() === $user->tenant_id) {
            return $expired();
        }
        // Read under the user's own tenant; an account whose tenant cannot be read is held at the page (fails closed).
        $tenant = $user->tenant()->first();

        return $tenant === null || $tenants->runAs($tenant, $expired);
    }
}
