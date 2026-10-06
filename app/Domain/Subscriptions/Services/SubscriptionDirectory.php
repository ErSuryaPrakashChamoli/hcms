<?php

namespace App\Domain\Subscriptions\Services;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Entitlements\Models\PlanVersion;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Subscriptions\Models\SubscriptionPeriod;
use App\Domain\Subscriptions\Support\SubscriptionTimeline;
use Illuminate\Support\Collection;

/**
 * SaaS.6: read models for platform operators (Platform › Subscriptions). Read-only. The cross-tenant overview is the
 * one place subscription periods are read without a bound tenant (tenant names and commercial states only, no HCM
 * data); the audit trail reads Markedge's own platform chain, which has no tenant.
 */
final class SubscriptionDirectory
{
    /**
     * Every tenant with its technical status and its commercial state on $day (two reads, whatever the tenant count).
     *
     * @return list<array{tenant: Tenant, state: ?array, subscription_id: ?int, version: ?string}>
     */
    public function overview(string $day): array
    {
        $periods = SubscriptionPeriod::query()->withoutTenancy()->whereNull('voided_at')->orderBy('starts_on')->get()->groupBy('tenant_id');
        $versions = PlanVersion::query()->with('plan')->whereKey($periods->flatten()->pluck('plan_version_id')->unique()->all())->get()->keyBy('id');
        $rows = [];
        foreach (Tenant::query()->orderBy('name')->get() as $tenant) {
            $state = null;
            $subscriptionId = null;
            foreach (($periods[$tenant->id] ?? collect())->groupBy('subscription_id') as $id => $rowsOfOne) {
                $candidate = SubscriptionTimeline::of($rowsOfOne)->stateOn($day);
                if ($candidate !== null && ($state === null || ! $candidate['status']->terminal())) {
                    [$state, $subscriptionId] = [$candidate, (int) $id];
                }
            }
            $rows[] = ['tenant' => $tenant, 'state' => $state, 'subscription_id' => $subscriptionId,
                'version' => $state ? $versions->get($state['plan_version_id'])?->label() : null];
        }

        return $rows;
    }

    /**
     * Platform-chain audit events of one tenant's subscriptions, newest first, with their before / after values.
     *
     * @return Collection<int, AuditEvent>
     */
    public function auditTrail(int $tenantId, int $limit = 50): Collection
    {
        return AuditEvent::query()->withoutTenancy()->whereNull('tenant_id')->where('module', 'subscriptions')
            ->where('metadata->subject_tenant_id', $tenantId)->with('fieldChanges')->orderByDesc('id')->limit($limit)->get();
    }
}
