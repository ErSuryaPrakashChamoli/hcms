<?php

namespace App\Domain\Entitlements\Services;

use App\Domain\Entitlements\Models\TenantEntitlementProfile;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * SaaS.3 (extracted in SaaS.6): the one serialisation point for a tenant's commercial state: entitlement
 * configuration, overrides, plan assignments and (SaaS.6) the subscription timeline that projects onto them.
 *
 * Runs the work with the tenant bound and its tenant_entitlement_profiles row locked (FOR UPDATE), never the
 * tenants row. The profile row is created first, outside the transaction (INSERT IGNORE), so two first-time
 * writers never deadlock on the insert. The cached entitlement state is forgotten after commit.
 */
final class TenantCommercialLock
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @template T
     *
     * @param  Closure(TenantEntitlementProfile): T  $work
     * @return T
     */
    public function run(Tenant $tenant, Closure $work): mixed
    {
        return $this->tenants->runAs($tenant, function () use ($tenant, $work) {
            TenantEntitlementProfile::query()->insertOrIgnore(['tenant_id' => $tenant->id, 'state' => TenantEntitlementProfile::UNCONFIGURED, 'version' => 0,
                'created_at' => now(), 'updated_at' => now()]);

            return DB::transaction(function () use ($tenant, $work) {
                $profile = TenantEntitlementProfile::query()->lockForUpdate()->firstOrFail();
                $result = $work($profile);
                DB::afterCommit(fn () => app(EntitlementStateStore::class)->forget($tenant->id));

                return $result;
            });
        });
    }
}
