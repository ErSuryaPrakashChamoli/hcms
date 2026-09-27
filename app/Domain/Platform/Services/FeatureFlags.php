<?php

namespace App\Domain\Platform\Services;

use App\Domain\Platform\Models\TenantFeature;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Cache\Repository as Cache;

final class FeatureFlags
{
    private const TTL_SECONDS = 3600;

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Cache $cache,
    ) {}

    public function enabled(string $feature): bool
    {
        $flags = $this->all();

        return (bool) ($flags[$feature] ?? config("peopleos.features.{$feature}.enabled", false));
    }

    public function disabled(string $feature): bool
    {
        return ! $this->enabled($feature);
    }

    /** @return array<string, bool> */
    public function all(): array
    {
        $tenantId = $this->tenants->id();

        $defaults = collect(config('peopleos.features', []))->map(fn (array $f) => (bool) $f['enabled'])->all();

        if ($tenantId === null) {
            return $defaults;
        }

        $stored = $this->cache->remember(
            $this->cacheKey($tenantId),
            self::TTL_SECONDS,
            fn () => TenantFeature::query()->pluck('enabled', 'feature')->map(fn ($v) => (bool) $v)->all(),
        );

        return array_merge($defaults, $stored);
    }

    public function set(string $feature, bool $enabled, ?string $reason = null): TenantFeature
    {
        $flag = TenantFeature::query()->firstOrNew(['feature' => $feature]);
        $flag->enabled = $enabled;
        $flag->withAuditReason($reason)->save();

        $this->forget();

        return $flag;
    }

    public function forget(?int $tenantId = null): void
    {
        $tenantId ??= $this->tenants->id();

        if ($tenantId !== null) {
            $this->cache->forget($this->cacheKey($tenantId));
        }
    }

    private function cacheKey(int $tenantId): string
    {
        return "tenant:{$tenantId}:features";
    }
}
