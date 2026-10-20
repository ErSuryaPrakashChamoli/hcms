<?php

namespace App\Domain\Integration\Services;

use App\Domain\Integration\Models\ApiKey;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class ApiKeys
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @param  list<string>  $scopes
     * @return array{key: ApiKey, plaintext: string}
     */
    public function issue(string $name, array $scopes, ?\DateTimeInterface $expiresAt = null): array
    {
        $prefix = 'pk_'.Str::lower(Str::random(10));
        $secret = Str::random(40);

        $key = ApiKey::create([
            'name' => $name,
            'prefix' => $prefix,
            'secret_hash' => hash('sha256', $secret),
            'scopes' => array_values($scopes),
            'expires_at' => $expiresAt,
            'created_by' => auth()->id(),
        ]);

        return ['key' => $key, 'plaintext' => "{$prefix}.{$secret}"];
    }

    /**
     * SaaS.8: the request's key, resolved once per request (the rate limiter needs it before AuthenticateApiKey runs, and
     * both must agree). Only a verified key is returned.
     */
    public function resolveFor(Request $request): ?ApiKey
    {
        $token = $request->header('X-Api-Key') ?? $request->bearerToken();
        $memo = $request->attributes->get('peopleos.api_key_resolution');
        $fingerprint = hash('sha256', (string) $token);
        if (is_array($memo) && $memo['token'] === $fingerprint) {
            return $memo['key'];
        }
        $key = $this->resolve($token);
        $request->attributes->set('peopleos.api_key_resolution', ['token' => $fingerprint, 'key' => $key]);

        return $key;
    }

    /** Resolve a presented token to its key (cross-tenant lookup by design; the key carries the tenant). */
    public function resolve(?string $token): ?ApiKey
    {
        if (! $token || ! str_contains($token, '.')) {
            return null;
        }

        [$prefix, $secret] = explode('.', $token, 2);

        $key = $this->tenants->bypass(fn () => ApiKey::query()->where('prefix', $prefix)->first());

        if ($key === null || ! hash_equals($key->secret_hash, hash('sha256', $secret)) || ! $key->isUsable()) {
            return null;
        }
        // Phase 14: a suspended or closed tenant's keys stop authenticating with the tenant itself.
        $tenant = $this->tenants->bypass(fn () => $key->tenant()->first());
        if ($tenant === null || ! $tenant->isAccessible()) {
            return null;
        }

        return $key;
    }

    public function revoke(ApiKey $key, ?string $reason = null): void
    {
        $key->withAuditReason($reason)->update(['status' => 'inactive']);
    }
}
