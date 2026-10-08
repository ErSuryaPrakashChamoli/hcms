<?php

namespace App\Http\Middleware;

use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Services\Entitlements;
use App\Domain\Integration\Services\ApiKeys;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * X-Api-Key: <prefix>.<secret>. Binds the key's tenant and stamps the audit source (§87).
 * Usage: middleware('api.key:rms.write') to require a scope.
 */
class AuthenticateApiKey
{
    public function __construct(private readonly ApiKeys $keys, private readonly TenantContext $tenants) {}

    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $key = $this->keys->resolve($request->header('X-Api-Key') ?? $request->bearerToken());

        if ($key === null) {
            return response()->json(['message' => 'Invalid or expired API key.'], 401);
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
}
