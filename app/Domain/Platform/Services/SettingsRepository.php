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

    /**
     * SaaS.2: per-request memo of each tenant's settings. The security policy is read on every request (IP
     * allow-list, idle timeout, MFA requirement); with a database-backed cache each read was a query. The memo
     * is process-wide (every instance, including ones held by long-lived services, sees one answer), cleared
     * by forget() together with the cache, and flushed at the end of every request and before every queued job
     * (AppServiceProvider), so no answer outlives its request or job.
     *
     * @var array<int, array<string, mixed>>
     */
    private static array $memo = [];

    public static function flushMemo(): void
    {
        self::$memo = [];
    }

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

        return self::$memo[$tenantId] ??= array_merge(config('peopleos.settings', []), $this->cache->remember(
            $this->cacheKey($tenantId),
            self::TTL_SECONDS,
            fn () => TenantSetting::query()->pluck('value', 'key')->all(),
        ));
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
            unset(self::$memo[$tenantId]);
            $this->cache->forget($this->cacheKey($tenantId));
        }
    }

    private function cacheKey(int $tenantId): string
    {
        return "tenant:{$tenantId}:settings";
    }
}
