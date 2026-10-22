<?php

namespace App\Domain\Platform\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Models\TenantFeature;
use App\Domain\Platform\Support\PlatformOperator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Cache\Repository as Cache;
use InvalidArgumentException;
use RuntimeException;

final class FeatureFlags
{
    private const TTL_SECONDS = 3600;

    /** @var array<int, array<string, bool>> UX.18: this request's flags per tenant (one cache read instead of one per check) */
    private array $loaded = [];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Cache $cache,
        private readonly AuditRecorder $audit,
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

        $stored = $this->loaded[$tenantId] ??= $this->cache->remember(
            $this->cacheKey($tenantId),
            self::TTL_SECONDS,
            fn () => TenantFeature::query()->pluck('enabled', 'feature')->map(fn ($v) => (bool) $v)->all(),
        );

        return array_merge($defaults, $stored);
    }

    /** A flag only a platform operator may change: tenants see it, never set it (Feature flags page, blueprints). */
    public static function platformControlled(string $feature): bool
    {
        return (bool) (config('peopleos.features')[$feature]['platform'] ?? false); // keys contain dots: no dot-path lookup
    }

    public function set(string $feature, bool $enabled, ?string $reason = null): TenantFeature
    {
        if (self::platformControlled($feature)) {
            throw new RuntimeException("The {$feature} feature is switched by the platform operator.");
        }

        return $this->write($feature, $enabled, $reason);
    }

    public function enabledFor(Tenant $tenant, string $feature): bool
    {
        return $this->tenants->runAs($tenant, fn () => $this->enabled($feature));
    }

    /**
     * SaaS.8 pattern: a platform operator and a reason. The flag row carries the change on the tenant's chain; the
     * platform chain gets its own record. Returns false when the flag already had that value.
     */
    public function setForTenant(Tenant $tenant, string $feature, bool $enabled, ?User $actor, ?string $reason): bool
    {
        $actor = PlatformOperator::assert($actor, 'switch a platform-controlled feature');
        $reason = PlatformOperator::reason($reason);
        if (! self::platformControlled($feature)) {
            throw new InvalidArgumentException("[{$feature}] is not a platform-controlled feature.");
        }

        $before = $this->enabledFor($tenant, $feature);
        if ($before === $enabled) {
            return false;
        }

        $this->tenants->runAs($tenant, fn () => $this->write($feature, $enabled, $reason, AuditAction::FeatureChanged));
        $this->audit->record(AuditAction::FeatureChanged, 'platform', null, [['field' => $feature, 'before' => $before, 'after' => $enabled]], $reason,
            entityLabel: "Tenant {$tenant->name}", actor: $actor, metadata: ['subject_tenant_id' => $tenant->getKey(), 'subject_tenant' => $tenant->slug, 'feature' => $feature], platform: true);

        return true;
    }

    private function write(string $feature, bool $enabled, ?string $reason, ?AuditAction $action = null): TenantFeature
    {
        $flag = TenantFeature::query()->firstOrNew(['feature' => $feature]);
        $flag->enabled = $enabled;
        $flag->withAuditAction($action)->withAuditReason($reason)->save();

        $this->forget();

        return $flag;
    }

    public function forget(?int $tenantId = null): void
    {
        $tenantId ??= $this->tenants->id();

        if ($tenantId !== null) {
            unset($this->loaded[$tenantId]);
            $this->cache->forget($this->cacheKey($tenantId));
        }
    }

    private function cacheKey(int $tenantId): string
    {
        return "tenant:{$tenantId}:features";
    }
}
