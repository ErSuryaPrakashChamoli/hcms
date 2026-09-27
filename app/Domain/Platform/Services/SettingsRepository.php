<?php

namespace App\Domain\Platform\Services;

use App\Domain\Platform\Models\TenantSetting;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Tenant settings with platform defaults (config/peopleos.php) underneath.
 */
final class SettingsRepository
{
    private const TTL_SECONDS = 3600;

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Cache $cache,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return array_key_exists($key, $all) ? $all[$key] : ($default ?? config("peopleos.settings.{$key}"));
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        $tenantId = $this->tenants->id();

        if ($tenantId === null) {
            return config('peopleos.settings', []);
        }

        $stored = $this->cache->remember(
            $this->cacheKey($tenantId),
            self::TTL_SECONDS,
            fn () => TenantSetting::query()->pluck('value', 'key')->all(),
        );

        return array_merge(config('peopleos.settings', []), $stored);
    }

    public function set(string $key, mixed $value, ?string $reason = null): TenantSetting
    {
        $setting = TenantSetting::query()->firstOrNew(['key' => $key]);
        $setting->value = $value;
        $setting->withAuditReason($reason)->save();

        $this->forget();

        return $setting;
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
        return "tenant:{$tenantId}:settings";
    }
}
