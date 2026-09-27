<?php

namespace App\Http\Middleware;

use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticated User -> Tenant. Tenant users are bound to their own tenant; platform admins are
 * bound to whichever tenant they have explicitly entered (stored in session), or none.
 */
class ResolveTenant
{
    public const SESSION_KEY = 'platform.active_tenant_id';

    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if ($user->tenant_id !== null) {
            $this->context->set($user->tenant);
        } elseif ($user->is_platform_admin && $request->session()->has(self::SESSION_KEY)) {
            $this->context->set(Tenant::find($request->session()->get(self::SESSION_KEY)));
        }

        return $next($request);
    }
}
