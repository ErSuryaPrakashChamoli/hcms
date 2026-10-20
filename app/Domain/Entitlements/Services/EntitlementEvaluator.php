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
 *   3. Configuration: from the tenant's configured-from date, the tenant's own tenant_entitlements row covering
 *      the date decides (tenant-specific terms are more specific than any plan).
 *   4. Plan (SaaS.4): the plan assignment covering the date points at a published, immutable plan version; that
 *      version's row decides. A capability the plan does not mention is NOT_IN_PLAN (DENY; "not sold", as opposed
 *      to NOT_ENTITLED, an explicit "not available"), or, for a limit, has no agreed limit (UNKNOWN).
 *   5. Configured, no row: a configured tenant without a row or plan for the capability is NOT entitled (DENY),
 *      or, for a limit, has no agreed limit (UNKNOWN).
 *   6. Otherwise UNKNOWN: no commercial configuration yet (or the date is before it), or a plan ended and nothing
 *      replaced it. Missing configuration is never read as DENY.
 * Then:
 *   - a feature is available only if its module is (an unknown module makes the feature unknown);
 *   - SaaS.5: a limit inside a commercial module (AI requests, API requests) is not included while its module is
 *     not entitled: DENY with MODULE_NOT_ENTITLED, whatever the limit's own value (an unknown module leaves the
 *     limit's own answer);
 *   - a limit compares the resolved value (null = unlimited) with the usage the caller measured: within or at
 *     the limit is ALLOW, above it DENY, no usage measured UNKNOWN.
 *
 * So the limit states never collapse: unlimited (ALLOW, UNLIMITED), not included (DENY, MODULE_NOT_ENTITLED), no
 * agreed limit or no commercial answer (UNKNOWN, with its reason), usage unmeasured (UNKNOWN, USAGE_UNAVAILABLE),
 * within (ALLOW, WITHIN_LIMIT) and exceeded (DENY, LIMIT_EXCEEDED).
 */
final class EntitlementEvaluator
{
    public function decide(EntitlementState $state, Capability $capability, string $day, ?int $usage = null): Decision
    {
        if (! $capability->commercial()) {
            $decision = new Decision($capability, DecisionOutcome::NotApplicable, DecisionReason::NotCommercial, DecisionSource::Catalog, $state->tenantId, $day);
        } else {
            $decision = $capability->type() === CapabilityType::Limit
                ? $this->limit($state, $capability, $day, $usage)
                : $this->toggle($state, $capability, $day);
        }

        // SaaS.6: the commercial state of the plan assignment in force (trial, active, grace) travels with the decision as
        // context; it never changes the outcome. Without an assignment (no plan, or a lapsed subscription) it is null.
        return $decision->withCommercialStatus($state->assignmentOn($day)['commercial_status'] ?? null);
    }

    private function toggle(EntitlementState $state, Capability $capability, string $day): Decision
    {
        $tenant = $state->tenantId;
        $row = $state->isConfiguredOn($day) ? $state->entitlementOn($capability, $day) : null;

        if ($override = $state->overrideOn($capability, $day)) {
            $decision = (bool) $override['value_bool']
                ? new Decision($capability, DecisionOutcome::Allow, DecisionReason::OverrideGranted, DecisionSource::Override, $tenant, $day, overrideId: $override['id'])
                : new Decision($capability, DecisionOutcome::Deny, DecisionReason::OverrideDenied, DecisionSource::Override, $tenant, $day, overrideId: $override['id']);
        } elseif ($row !== null) {
            $decision = (bool) $row['value_bool']
                ? new Decision($capability, DecisionOutcome::Allow, DecisionReason::Entitled, DecisionSource::Configuration, $tenant, $day, entitlementId: $row['id'])
                : new Decision($capability, DecisionOutcome::Deny, DecisionReason::NotEntitled, DecisionSource::Configuration, $tenant, $day, entitlementId: $row['id']);
        } elseif ($assignment = $state->assignmentOn($day)) {
            $versionId = (int) $assignment['plan_version_id'];
            $planned = $state->planEntitlement($versionId, $capability);
            [$outcome, $reason] = match (true) {
                $planned === null => [DecisionOutcome::Deny, DecisionReason::NotInPlan],
                (bool) $planned['value_bool'] => [DecisionOutcome::Allow, DecisionReason::Entitled],
                default => [DecisionOutcome::Deny, DecisionReason::NotEntitled],
            };
            $decision = new Decision($capability, $outcome, $reason, DecisionSource::Plan, $tenant, $day, assignmentId: $assignment['id'], planVersionId: $versionId);
        } elseif ($state->isConfiguredOn($day)) {
            $decision = new Decision($capability, DecisionOutcome::Deny, DecisionReason::NotEntitled, DecisionSource::Configuration, $tenant, $day);
        } else {
            return Decision::unknown($capability, $this->unconfiguredReason($state, $day), $tenant, $day);
        }

        // A feature never outlives its module (override the module too, if that is intended).
        if ($capability->type() === CapabilityType::Feature && $decision->allowed() && $capability->module()->commercial()) {
            $module = $this->toggle($state, $capability->module(), $day);
            if ($module->outcome === DecisionOutcome::Deny) {
                return new Decision($capability, DecisionOutcome::Deny, DecisionReason::ModuleNotEntitled, $module->source, $tenant, $day, $module->entitlementId, $module->overrideId,
                    assignmentId: $module->assignmentId, planVersionId: $module->planVersionId);
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

        // SaaS.5: a limit never outlives its module (the limit's dimension is not included in what the tenant has).
        if ($capability->followsModule()) {
            $module = $this->toggle($state, $capability->module(), $day);
            if ($module->outcome === DecisionOutcome::Deny) {
                return new Decision($capability, DecisionOutcome::Deny, DecisionReason::ModuleNotEntitled, $module->source, $tenant, $day, $module->entitlementId, $module->overrideId,
                    usage: $usage, assignmentId: $module->assignmentId, planVersionId: $module->planVersionId);
            }
        }
        $row = $state->isConfiguredOn($day) ? $state->entitlementOn($capability, $day) : null;
        [$entitlementId, $overrideId, $assignmentId, $versionId] = [null, null, null, null];

        if ($override = $state->overrideOn($capability, $day)) {
            [$value, $source, $overrideId] = [$override['value_int'], DecisionSource::Override, $override['id']];
        } elseif ($row !== null) {
            [$value, $source, $entitlementId] = [$row['value_int'], DecisionSource::Configuration, $row['id']];
        } elseif ($assignment = $state->assignmentOn($day)) {
            [$assignmentId, $versionId, $source] = [$assignment['id'], (int) $assignment['plan_version_id'], DecisionSource::Plan];
            $planned = $state->planEntitlement($versionId, $capability);
            if ($planned === null) {
                return new Decision($capability, DecisionOutcome::Unknown, DecisionReason::LimitNotConfigured, $source, $tenant, $day, usage: $usage,
                    assignmentId: $assignmentId, planVersionId: $versionId);
            }
            $value = $planned['value_int'];
        } elseif ($state->isConfiguredOn($day)) {
            return new Decision($capability, DecisionOutcome::Unknown, DecisionReason::LimitNotConfigured, DecisionSource::Configuration, $tenant, $day, usage: $usage);
        } else {
            return Decision::unknown($capability, $this->unconfiguredReason($state, $day), $tenant, $day);
        }

        $limit = $value === null ? null : (int) $value;
        [$outcome, $reason] = match (true) {
            $limit === null => [DecisionOutcome::Allow, DecisionReason::Unlimited],
            $usage === null => [DecisionOutcome::Unknown, DecisionReason::UsageUnavailable],
            $usage <= $limit => [DecisionOutcome::Allow, DecisionReason::WithinLimit],
            default => [DecisionOutcome::Deny, DecisionReason::LimitExceeded],
        };

        return new Decision($capability, $outcome, $reason, $source, $tenant, $day, $entitlementId, $overrideId, $limit, $usage,
            assignmentId: $assignmentId, planVersionId: $versionId);
    }

    /** Why there is no commercial answer on the day: a plan ended (a gap), nothing has started yet, or nothing ever. */
    private function unconfiguredReason(EntitlementState $state, string $day): DecisionReason
    {
        return match (true) {
            $state->hadPlanBy($day) => DecisionReason::NoPlanInForce,
            $state->configuredFrom !== null || $state->assignments !== [] => DecisionReason::BeforeConfiguration,
            default => DecisionReason::TenantUnconfigured,
        };
    }
}
