<?php

namespace App\Domain\Entitlements\Services;

use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Enums\CapabilityType;
use App\Domain\Entitlements\Enums\DecisionOutcome;
use App\Domain\Entitlements\Enums\DecisionReason;
use App\Domain\Entitlements\Enums\DecisionSource;
use App\Domain\Entitlements\Support\Decision;
use App\Domain\Entitlements\Support\EntitlementState;

/**
 * SaaS.3: the entitlement rules, as a pure function of (state, capability, business date, usage). No database,
 * cache, clock or tenant context is read here, so the same inputs always give the same decision.
 *
 * Precedence (first match wins):
 *   1. Catalogue: a non-commercial capability (the HCM core, security) is NOT_APPLICABLE.
 *   2. Override: an active platform override covering the date decides the value.
 *   3. Configuration: from the tenant's configured-from date, the tenant_entitlements row covering the date
 *      decides; a configured tenant without a row for the capability is NOT entitled (DENY), or, for a limit,
 *      has no agreed limit (UNKNOWN).
 *   4. Otherwise UNKNOWN: the tenant has no commercial configuration yet (or the date is before it). Missing
 *      configuration is never read as DENY.
 * Then:
 *   - a feature is available only if its module is (an unknown module makes the feature unknown);
 *   - a limit compares the resolved value (null = unlimited) with the usage the caller measured: within or at
 *     the limit is ALLOW, above it DENY, no usage measured UNKNOWN.
 */
final class EntitlementEvaluator
{
    public function decide(EntitlementState $state, Capability $capability, string $day, ?int $usage = null): Decision
    {
        if (! $capability->commercial()) {
            return new Decision($capability, DecisionOutcome::NotApplicable, DecisionReason::NotCommercial, DecisionSource::Catalog, $state->tenantId, $day);
        }

        return $capability->type() === CapabilityType::Limit
            ? $this->limit($state, $capability, $day, $usage)
            : $this->toggle($state, $capability, $day);
    }

    private function toggle(EntitlementState $state, Capability $capability, string $day): Decision
    {
        $tenant = $state->tenantId;

        if ($override = $state->overrideOn($capability, $day)) {
            $decision = (bool) $override['value_bool']
                ? new Decision($capability, DecisionOutcome::Allow, DecisionReason::OverrideGranted, DecisionSource::Override, $tenant, $day, overrideId: $override['id'])
                : new Decision($capability, DecisionOutcome::Deny, DecisionReason::OverrideDenied, DecisionSource::Override, $tenant, $day, overrideId: $override['id']);
        } elseif ($state->isConfiguredOn($day)) {
            $row = $state->entitlementOn($capability, $day);
            $decision = $row !== null && (bool) $row['value_bool']
                ? new Decision($capability, DecisionOutcome::Allow, DecisionReason::Entitled, DecisionSource::Configuration, $tenant, $day, entitlementId: $row['id'])
                : new Decision($capability, DecisionOutcome::Deny, DecisionReason::NotEntitled, DecisionSource::Configuration, $tenant, $day, entitlementId: $row['id'] ?? null);
        } else {
            return Decision::unknown($capability, $this->unconfiguredReason($state), $tenant, $day);
        }

        // A feature never outlives its module (override the module too, if that is intended).
        if ($capability->type() === CapabilityType::Feature && $decision->allowed() && $capability->module()->commercial()) {
            $module = $this->toggle($state, $capability->module(), $day);
            if ($module->outcome === DecisionOutcome::Deny) {
                return new Decision($capability, DecisionOutcome::Deny, DecisionReason::ModuleNotEntitled, $module->source, $tenant, $day, $module->entitlementId, $module->overrideId);
            }
            if ($module->outcome === DecisionOutcome::Unknown) {
                return Decision::unknown($capability, DecisionReason::ModuleUnknown, $tenant, $day);
            }
        }

        return $decision;
    }

    private function limit(EntitlementState $state, Capability $capability, string $day, ?int $usage): Decision
    {
        $tenant = $state->tenantId;

        if ($override = $state->overrideOn($capability, $day)) {
            [$value, $source, $entitlementId, $overrideId] = [$override['value_int'], DecisionSource::Override, null, $override['id']];
        } elseif ($state->isConfiguredOn($day)) {
            $row = $state->entitlementOn($capability, $day);
            if ($row === null) {
                return new Decision($capability, DecisionOutcome::Unknown, DecisionReason::LimitNotConfigured, DecisionSource::Configuration, $tenant, $day, usage: $usage);
            }
            [$value, $source, $entitlementId, $overrideId] = [$row['value_int'], DecisionSource::Configuration, $row['id'], null];
        } else {
            return Decision::unknown($capability, $this->unconfiguredReason($state), $tenant, $day);
        }

        $limit = $value === null ? null : (int) $value;
        [$outcome, $reason] = match (true) {
            $limit === null => [DecisionOutcome::Allow, DecisionReason::Unlimited],
            $usage === null => [DecisionOutcome::Unknown, DecisionReason::UsageUnavailable],
            $usage <= $limit => [DecisionOutcome::Allow, DecisionReason::WithinLimit],
            default => [DecisionOutcome::Deny, DecisionReason::LimitExceeded],
        };

        return new Decision($capability, $outcome, $reason, $source, $tenant, $day, $entitlementId, $overrideId, $limit, $usage);
    }

    private function unconfiguredReason(EntitlementState $state): DecisionReason
    {
        return $state->configuredFrom === null ? DecisionReason::TenantUnconfigured : DecisionReason::BeforeConfiguration;
    }
}
