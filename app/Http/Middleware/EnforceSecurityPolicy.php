<?php

namespace App\Http\Middleware;

use App\Domain\Enterprise\Services\SecurityPolicy;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Tenant security policy (§82): IP allowlist for tenant users, session idle timeout, per-user locale. */
class EnforceSecurityPolicy
{
    public function __construct(private readonly TenantContext $tenants, private readonly SecurityPolicy $policy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $locale = $user ? ($user->getAttributes()['locale'] ?? null) : null;
        if ($locale) {
            app()->setLocale(str_replace('-', '_', $locale));
        }

        if ($user && ! $user->is_platform_admin && $this->tenants->has()) {
            if (! $this->policy->ipAllowed($request->ip())) {
                abort(403, 'Your network is not allowed to access this tenant.');
            }

            $idle = $this->policy->idleMinutes();
            if ($idle > 0) {
                $last = (int) $request->session()->get('peopleos.last_activity', 0);
                if ($last > 0 && now()->timestamp - $last > $idle * 60) {
                    Auth::logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();

                    return redirect()->to('/admin/login')->with('status', 'You were signed out after '.$idle.' minutes of inactivity.');
                }
                $request->session()->put('peopleos.last_activity', now()->timestamp);
            }
        }

        return $next($request);
    }
}
