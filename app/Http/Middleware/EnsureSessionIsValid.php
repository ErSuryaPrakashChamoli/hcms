<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\SessionSecurity;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * SaaS.2: a signed-in session is only as good as the account and tenant behind it, checked on every
 * request (panel pages, Livewire calls and protected downloads), before anything else runs:
 * - the user is still active;
 * - a tenant user's tenant is still accessible (not suspended);
 * - the session was opened under the current session epochs (SessionSecurity), so a suspension, an
 *   MFA reset or a password reset ends sessions that already existed.
 * A failing session is signed out and invalidated, not merely refused, so reactivating the tenant
 * later does not bring it back. Platform operators are checked as users; their tenant access is
 * governed separately (PlatformTenantAccess).
 */
class EnsureSessionIsValid
{
    public function __construct(private readonly SessionSecurity $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || ! $request->hasSession()) {
            return $next($request);
        }

        $problem = match (true) {
            ! $user->isActive() => 'Your account is not active.',
            ! $user->isPlatformAdmin() && ! ($user->tenant?->isAccessible() ?? false) => 'Access to your organisation is suspended.',
            ! $this->sessions->isCurrent($request->session(), $user) => 'Your session has ended. Please sign in again.',
            default => null,
        };
        if ($problem === null) {
            return $next($request);
        }

        Auth::guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => $problem], 401);
        }

        return redirect()->to(Filament::getPanel('admin')->getLoginUrl())->with('status', $problem);
    }
}
