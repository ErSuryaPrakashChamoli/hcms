<?php

namespace App\Domain\Entitlements\Services;

use App\Domain\Entitlements\Models\EntitlementOverride;
use App\Domain\Entitlements\Models\TenantEntitlement;
use App\Domain\Entitlements\Models\TenantEntitlementProfile;
use App\Domain\Entitlements\Support\EntitlementState;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SaaS.3: loads a tenant's entitlement state, at most once per request or job.
 *
 * - Request memo: this service is request-scoped (AppServiceProvider); its memo dies with the request, with each
 *   queued job (Laravel forgets scoped instances per job) and at the end of every handled request. Callers always
 *   resolve it from the container at call time, so no long-lived object can hold a stale memo. Keyed by tenant id.
 * - Cache: `tenant:{id}:entitlements` on the default store, separate from every authorisation cache; forgotten
 *   after commit by every configuration change. A cache failure falls back to the database.
 * - Database: three indexed, tenant-scoped reads (profile, active configuration rows, active overrides).
 */
final class EntitlementStateStore
{
    /** @var array<int, EntitlementState> */
    private array $memo = [];

    public function __construct(private readonly Cache $cache, private readonly TenantContext $tenants) {}

    public static function cacheKey(int $tenantId): string
    {
        return "tenant:{$tenantId}:entitlements";
    }

    public function for(int $tenantId): EntitlementState
    {
        return $this->memo[$tenantId] ??= $this->cached($tenantId);
    }

    public function forget(int $tenantId): void
    {
        unset($this->memo[$tenantId]);
        try {
            $this->cache->forget(self::cacheKey($tenantId));
        } catch (Throwable $e) {
            Log::warning('entitlements.cache_forget_failed', ['tenant_id' => $tenantId, 'error' => $e::class]);
        }
    }

    private function cached(int $tenantId): EntitlementState
    {
        try {
            $data = $this->cache->remember(self::cacheKey($tenantId), (int) config('peopleos.entitlements.cache_seconds', 600), fn () => $this->load($tenantId)->toArray());

            return EntitlementState::fromArray($data);
        } catch (Throwable $e) {
            Log::warning('entitlements.cache_unavailable', ['tenant_id' => $tenantId, 'error' => $e::class]);

            return $this->load($tenantId);
        }
    }

    /** Reads the tenant's configuration (in its own tenant context; never across tenants). */
    public function load(int $tenantId): EntitlementState
    {
        $read = function () use ($tenantId): EntitlementState {
            $profile = TenantEntitlementProfile::query()->first();
            if ($profile === null) {
                return EntitlementState::unconfigured($tenantId);
            }
            $rows = fn (string $model) => $model::query()->where('status', $model::ACTIVE)->orderBy('id')->get()->map->toStateRow()->values()->all();

            return new EntitlementState(
                $tenantId,
                $profile->state === TenantEntitlementProfile::CONFIGURED ? $profile->configured_from?->toDateString() : null,
                $rows(TenantEntitlement::class),
                $rows(EntitlementOverride::class),
                (int) $profile->version,
            );
        };

        return $this->tenants->id() === $tenantId
            ? $read()
            : $this->tenants->runAs(Tenant::query()->findOrFail($tenantId), $read);
    }
}
