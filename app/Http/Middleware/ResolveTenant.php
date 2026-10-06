<?php

namespace App\Http\Middleware;

use App\Domain\Platform\Services\PlatformTenantAccess;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticated User -> Tenant. Tenant users are bound to their own tenant. Platform operators are bound
 * only through a controlled, audited, time-boxed access grant (SaaS.2, PlatformTenantAccess), or to none.
 */
class ResolveTenant
{
    /** Pre-SaaS.2 ungoverned "entered tenant" key. No longer honoured; removed from any session that still has it. */
    public const SESSION_KEY = 'platform.active_tenant_id';

    public function __construct(private readonly TenantContext $context, private readonly PlatformTenantAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        Context::forget('platform.access_id');
        if ($user === null) {
            return $next($request);
        }

        if ($user->tenant_id !== null) {
            $this->context->set($user->tenant);
        } elseif ($user->is_platform_admin && $request->hasSession()) {
            $request->session()->forget(self::SESSION_KEY);
            $tenant = $this->access->activeTenant($user, $request->session());
            if ($tenant !== null) {
                $this->context->set($tenant);
                Context::add('platform.access_id', $this->access->grant($request->session())['id']);
            }
        }

        return $next($request);
    }
}
