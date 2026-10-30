<?php

namespace App\Http\Middleware;

use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Services\Entitlements;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Integration\Services\ApiRequestLogs;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * X-Api-Key: <prefix>.<secret>. Binds the key's tenant and stamps the audit source (§87).
 * Usage: middleware('api.key:rms.write') to require a scope.
 *
 * HROne parity CA-11: a key with an IP allowlist is refused (403 ip_not_allowed) from any other address, before its
 * scopes are looked at or its tenant is bound; and every request made with a verified key — answered or refused,
 * including by the rate limiter — is written to the key's call log after the response (terminate()): method, path,
 * route, status, duration, IP and user agent only.
 */
class AuthenticateApiKey
{
    public function __construct(private readonly ApiKeys $keys, private readonly TenantContext $tenants) {}

    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $key = $this->keys->resolveFor($request);

        if ($key === null) {
            return response()->json(['message' => 'Invalid or expired API key.'], 401);
        }

        if (! $key->allowsIp($request->ip())) {
            return response()->json(['message' => 'This API key may not be used from this address.', 'code' => 'ip_not_allowed'], 403);
        }

        foreach ($scopes as $scope) {
            if (! $key->hasScope($scope)) {
                return response()->json(['message' => "This key lacks the [{$scope}] scope."], 403);
            }
        }

        $this->tenants->set($key->tenant);
        $key->forceFill(['last_used_at' => now()])->saveQuietly();

        // SaaS.3: shadow entitlement observations for the API itself and the module behind each required scope.
        // Scopes and the tenant still decide access, exactly as before; these never refuse a request.
        $entitlements = app(Entitlements::class);
        $entitlements->observe(Capability::IntegrationsApi, 'api.request');
        foreach ($scopes as $scope) {
            if (($capability = Capability::forApiScope($scope)) !== null) {
                $entitlements->observe($capability, 'api.request');
            }
        }

        Context::add('audit.source', 'api:'.$key->name);
        Context::add('api_key_id', $key->id);
        $request->attributes->set('api_key', $key);

        return $next($request);
    }

    /** CA-11: the call log, written after the response was sent (only for a verified key; never a body or header). */
    public function terminate(Request $request, Response $response): void
    {
        // Once per request, even where a route stacks two api.key middleware (a group's scope and its own).
        if ($request->attributes->get('peopleos.api_request_logged') === true) {
            return;
        }
        $key = $this->keys->resolveFor($request);
        if ($key !== null) {
            $request->attributes->set('peopleos.api_request_logged', true);
            app(ApiRequestLogs::class)->record($key, $request, $response);
        }
    }
}
