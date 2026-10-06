<?php

namespace App\Domain\Entitlements\Services;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Enums\CapabilityType;
use App\Domain\Entitlements\Models\EntitlementShadowObservation;
use App\Domain\Entitlements\Models\Plan;
use App\Domain\Entitlements\Models\TenantPlanAssignment;
use App\Domain\Entitlements\Support\Decision;
use App\Domain\Entitlements\Support\EntitlementState;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * SaaS.3: answers "why did tenant X get this decision?" and "what would have been denied?" for platform operators
 * (the Entitlements page and the console commands). Read-only. The cross-tenant shadow summary is the one place
 * that reads observations without a bound tenant (counts and keys only; no tenant data).
 * SaaS.4: the explanation names the plan in force (tenant → plan → version → capability), and operators see how
 * many tenants each plan version has (counts only; again no tenant data crosses) and Markedge's platform-chain
 * audit of a plan or of one tenant's commercial configuration (who, what, before and after, why, from when).
 */
final class EntitlementDiagnostics
{
    public function __construct(private readonly EntitlementStateStore $store, private readonly EntitlementEvaluator $evaluator) {}

    /** Every capability's decision for one tenant on one business date, with the layers behind it (one state read). */
    public function catalog(Tenant $tenant, ?string $day = null): Collection
    {
        $day = Carbon::parse($day ?? now())->toDateString();
        $state = $this->store->load($tenant->id);

        return collect(Capability::cases())->map(fn (Capability $c) => $this->explain($tenant, $c, $day, $state));
    }

    /**
     * @return array{decision: Decision, configured_from: ?string, version: int, configuration: ?array, override: ?array, module: ?Decision,
     *     assignment: ?array, plan: ?array, plan_entitlement: ?array}
     */
    public function explain(Tenant $tenant, Capability $capability, ?string $day = null, ?EntitlementState $state = null): array
    {
        $day = Carbon::parse($day ?? now())->toDateString();
        $state ??= $this->store->load($tenant->id);
        $assignment = $state->assignmentOn($day);
        $plan = $assignment ? $state->plan((int) $assignment['plan_version_id']) : null;

        return [
            'decision' => $this->evaluator->decide($state, $capability, $day),
            'configured_from' => $state->configuredFrom,
            'version' => $state->version,
            'configuration' => $state->entitlementOn($capability, $day),
            'override' => $state->overrideOn($capability, $day),
            'module' => $capability->type() === CapabilityType::Feature ? $this->evaluator->decide($state, $capability->module(), $day) : null,
            'assignment' => $assignment,
            'plan' => $plan === null ? null : array_diff_key($plan, ['entitlements' => true]),
            'plan_entitlement' => $assignment ? $state->planEntitlement((int) $assignment['plan_version_id'], $capability) : null,
        ];
    }

    /**
     * SaaS.4: per plan version, how many tenants are on it on $day and how many are assigned to it from a later date.
     * Counts only, across tenants (platform operators).
     *
     * @return array<int, array{current: int, upcoming: int}>
     */
    public function tenantsByPlanVersion(?string $day = null): array
    {
        $day = Carbon::parse($day ?? now())->toDateString();

        return TenantPlanAssignment::query()->withoutTenancy()->where('status', TenantPlanAssignment::ACTIVE)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
            ->selectRaw('plan_version_id, COUNT(DISTINCT CASE WHEN DATE(effective_from) <= ? THEN tenant_id END) AS current_tenants, COUNT(DISTINCT CASE WHEN DATE(effective_from) > ? THEN tenant_id END) AS upcoming_tenants', [$day, $day])
            ->groupBy('plan_version_id')->toBase()->get()
            ->mapWithKeys(fn ($r) => [(int) $r->plan_version_id => ['current' => (int) $r->current_tenants, 'upcoming' => (int) $r->upcoming_tenants]])->all();
    }

    /**
     * SaaS.4: platform-chain audit events about one plan, or about one tenant's commercial configuration, newest
     * first, with their before / after values. Markedge's own chain: it has no tenant, so it is read without one.
     *
     * @return Collection<int, AuditEvent>
     */
    public function auditTrail(?Plan $plan = null, ?int $tenantId = null, int $limit = 50): Collection
    {
        return AuditEvent::query()->withoutTenancy()->whereNull('tenant_id')->where('module', 'entitlements')
            ->when($plan !== null, fn ($q) => $q->where('entity_type', Plan::class)->where('entity_id', (string) $plan->id))
            ->when($tenantId !== null, fn ($q) => $q->where('metadata->subject_tenant_id', $tenantId))
            ->with('fieldChanges')->orderByDesc('id')->limit($limit)->get();
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
