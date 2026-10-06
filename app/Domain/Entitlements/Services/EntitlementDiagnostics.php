<?php

namespace App\Domain\Entitlements\Services;

use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Enums\CapabilityType;
use App\Domain\Entitlements\Models\EntitlementShadowObservation;
use App\Domain\Entitlements\Support\Decision;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * SaaS.3: answers "why did tenant X get this decision?" and "what would have been denied?" for platform operators
 * (the Entitlements page and the console commands). Read-only. The cross-tenant shadow summary is the one place
 * that reads observations without a bound tenant (counts and keys only; no tenant data).
 */
final class EntitlementDiagnostics
{
    public function __construct(private readonly EntitlementStateStore $store, private readonly EntitlementEvaluator $evaluator) {}

    /** Every capability's decision for one tenant on one business date, with the layers behind it. */
    public function catalog(Tenant $tenant, ?string $day = null): Collection
    {
        $day = Carbon::parse($day ?? now())->toDateString();

        return collect(Capability::cases())->map(fn (Capability $c) => $this->explain($tenant, $c, $day));
    }

    /** @return array{decision: Decision, configured_from: ?string, configuration: ?array, override: ?array, module: ?Decision} */
    public function explain(Tenant $tenant, Capability $capability, ?string $day = null): array
    {
        $day = Carbon::parse($day ?? now())->toDateString();
        $state = $this->store->load($tenant->id);

        return [
            'decision' => $this->evaluator->decide($state, $capability, $day),
            'configured_from' => $state->configuredFrom,
            'version' => $state->version,
            'configuration' => $state->entitlementOn($capability, $day),
            'override' => $state->overrideOn($capability, $day),
            'module' => $capability->type() === CapabilityType::Feature ? $this->evaluator->decide($state, $capability->module(), $day) : null,
        ];
    }

    /**
     * Shadow observations of the last $days days, summed by capability, outcome and reason (and surface), for one
     * tenant or for all. Platform operators only.
     *
     * @return Collection<int, object>
     */
    public function shadowSummary(int $days = 7, ?int $tenantId = null, bool $bySurface = false): Collection
    {
        $columns = $bySurface ? ['capability', 'outcome', 'reason', 'surface'] : ['capability', 'outcome', 'reason'];

        return EntitlementShadowObservation::query()->withoutTenancy()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('observed_on', '>=', now()->subDays(max(1, $days) - 1)->toDateString())
            ->selectRaw(implode(', ', $columns).', SUM(occurrences) AS occurrences, COUNT(DISTINCT tenant_id) AS tenants, MAX(last_seen_at) AS last_seen_at')
            ->groupBy($columns)
            ->orderByRaw("CASE outcome WHEN 'DENY' THEN 0 WHEN 'UNKNOWN' THEN 1 ELSE 2 END")
            ->orderBy('capability')
            ->toBase()->get();
    }
}
