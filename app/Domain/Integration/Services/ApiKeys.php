<?php

namespace App\Domain\Integration\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Integration\Models\ApiKey;
use App\Domain\Integration\Support\IpAllowlist;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\TenantContext;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class ApiKeys
{
    /** CA-11: the highest per-key limit a tenant may set (requests per minute). */
    public const MAX_RATE_LIMIT = 10000;

    /** Fields an existing key may change (each change audited with the reason). */
    public const EDITABLE = ['name', 'description', 'scopes', 'allowed_ips', 'rate_limit_per_minute', 'expires_at'];

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @param  list<string>  $scopes
     * @param  array{description?: ?string, allowed_ips?: string|list<string>|null, rate_limit_per_minute?: int|string|null, regenerated_from_id?: ?int, created_by?: ?int}  $options
     * @return array{key: ApiKey, plaintext: string}
     */
    public function issue(string $name, array $scopes, ?DateTimeInterface $expiresAt = null, array $options = []): array
    {
        $prefix = 'pk_'.Str::lower(Str::random(10));
        $secret = Str::random(40);
        $limits = $this->limits($options);

        $key = ApiKey::create([
            'name' => $name,
            'description' => $this->description($options['description'] ?? null),
            'prefix' => $prefix,
            'secret_hash' => hash('sha256', $secret),
            'scopes' => array_values($scopes),
            ...$limits,
            'expires_at' => $expiresAt,
            // Set explicitly (the column defaults to active): the returned model must know its own status.
            'status' => ActiveStatus::Active,
            'created_by' => $options['created_by'] ?? auth()->id(),
            'regenerated_from_id' => $options['regenerated_from_id'] ?? null,
        ]);

        return ['key' => $key, 'plaintext' => "{$prefix}.{$secret}"];
    }

    /**
     * CA-11 / SV-26: changes a key's name, description, scopes, IP allowlist, rate limit or expiry (audited with the
     * reason). The secret never changes here (see regenerate()); a revoked key is never changed.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(ApiKey $key, array $data, User $actor, ?string $reason = null): ApiKey
    {
        $this->authorise($actor);
        if ($key->status !== ActiveStatus::Active) {
            throw new RuntimeException('A revoked key cannot be changed.');
        }
        $data = array_intersect_key($data, array_flip(self::EDITABLE));
        $changes = [];
        foreach (['name', 'scopes', 'expires_at'] as $field) {
            if (array_key_exists($field, $data)) {
                $changes[$field] = $field === 'scopes' ? array_values((array) $data[$field]) : $data[$field];
            }
        }
        if (array_key_exists('description', $data)) {
            $changes['description'] = $this->description($data['description']);
        }
        if (array_key_exists('allowed_ips', $data) || array_key_exists('rate_limit_per_minute', $data)) {
            $limits = $this->limits([
                'allowed_ips' => array_key_exists('allowed_ips', $data) ? $data['allowed_ips'] : $key->ipAllowlist(),
                'rate_limit_per_minute' => array_key_exists('rate_limit_per_minute', $data) ? $data['rate_limit_per_minute'] : ($key->getAttributes()['rate_limit_per_minute'] ?? null),
            ]);
            $changes += $limits;
        }
        if (array_key_exists('scopes', $changes) && $changes['scopes'] === []) {
            throw new RuntimeException('A key needs at least one scope.');
        }
        $key->withAuditReason($reason)->update($changes);

        return $key;
    }

    /**
     * SV-26 ("Regenerate"): revokes the key at once and issues a new one with the same name, description, scopes, expiry,
     * IP allowlist and rate limit, in one step. The new secret is returned to be shown once. Audited: the old key's
     * revocation (with the reason) and API_KEY_REGENERATED on the new key, naming the key it replaced.
     *
     * @return array{key: ApiKey, plaintext: string}
     */
    public function regenerate(ApiKey $key, User $actor, string $reason): array
    {
        $this->authorise($actor);
        if (trim($reason) === '') {
            throw new RuntimeException('Give a reason for regenerating the key.');
        }

        return DB::transaction(function () use ($key, $actor, $reason) {
            $old = ApiKey::query()->lockForUpdate()->findOrFail($key->getKey());
            if (! $old->isUsable()) {
                throw new RuntimeException('Only an active, unexpired key can be regenerated; issue a new key instead.');
            }
            $old->withAuditReason('Regenerated: '.$reason)->update(['status' => ActiveStatus::Inactive]);
            $issued = $this->issue($old->name, $old->scopes ?? [], $old->expires_at, [
                'description' => $old->getAttributes()['description'] ?? null,
                'allowed_ips' => $old->ipAllowlist(),
                'rate_limit_per_minute' => $old->getAttributes()['rate_limit_per_minute'] ?? null,
                'regenerated_from_id' => $old->id,
                'created_by' => $actor->id,
            ]);
            app(AuditRecorder::class)->record(AuditAction::ApiKeyRegenerated, 'integration', $issued['key'], [], $reason, actor: $actor,
                metadata: ['previous_key_id' => $old->id, 'previous_prefix' => $old->prefix, 'prefix' => $issued['key']->prefix]);
            $key->refresh();

            return $issued;
        });
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

    /**
     * CA-11: validates the IP allowlist (every entry an IP or a CIDR block; refused otherwise, so a typo never silently
     * widens or empties the list) and the rate limit (1 … MAX_RATE_LIMIT, or empty for the API-wide limit).
     *
     * @param  array<string, mixed>  $options
     * @return array{allowed_ips: list<string>|null, rate_limit_per_minute: int|null}
     */
    public function limits(array $options): array
    {
        $ips = IpAllowlist::normalise($options['allowed_ips'] ?? null);
        if (($invalid = IpAllowlist::invalid($ips)) !== []) {
            throw new RuntimeException('Not an IP address or CIDR block: '.implode(', ', array_slice($invalid, 0, 5)).'.');
        }
        if (count($ips) > IpAllowlist::MAX_ENTRIES) {
            throw new RuntimeException('At most '.IpAllowlist::MAX_ENTRIES.' IP addresses or blocks per key.');
        }
        $rate = $options['rate_limit_per_minute'] ?? null;
        if ($rate !== null && $rate !== '') {
            if (filter_var($rate, FILTER_VALIDATE_INT) === false || (int) $rate < 1 || (int) $rate > self::MAX_RATE_LIMIT) {
                throw new RuntimeException('The rate limit must be a whole number from 1 to '.self::MAX_RATE_LIMIT.' requests a minute (or empty for the default).');
            }
            $rate = (int) $rate;
        } else {
            $rate = null;
        }

        return ['allowed_ips' => $ips === [] ? null : $ips, 'rate_limit_per_minute' => $rate];
    }

    private function description(mixed $description): ?string
    {
        $text = trim((string) $description);

        return $text === '' ? null : mb_substr($text, 0, 255);
    }

    private function authorise(User $actor): void
    {
        if (! $actor->hasPermission('api_key.manage')) {
            throw new RuntimeException('This needs api_key.manage.');
        }
    }
}
