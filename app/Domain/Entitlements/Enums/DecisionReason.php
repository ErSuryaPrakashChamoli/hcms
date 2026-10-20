<?php

namespace App\Domain\Entitlements\Enums;

/** SaaS.3: why the evaluator answered as it did. Every decision carries exactly one reason. */
enum DecisionReason: string
{
    // ALLOW
    case Entitled = 'ENTITLED';
    case OverrideGranted = 'OVERRIDE_GRANTED';
    case WithinLimit = 'WITHIN_LIMIT';
    case Unlimited = 'UNLIMITED';
    // DENY
    case NotEntitled = 'NOT_ENTITLED';
    case OverrideDenied = 'OVERRIDE_DENIED';
    case ModuleNotEntitled = 'MODULE_NOT_ENTITLED';
    case LimitExceeded = 'LIMIT_EXCEEDED';
    // SaaS.4: the tenant's plan does not include it ("not sold"), as opposed to NOT_ENTITLED, an explicit "not available".
    case NotInPlan = 'NOT_IN_PLAN';
    // UNKNOWN
    case TenantUnconfigured = 'TENANT_UNCONFIGURED';
    case BeforeConfiguration = 'BEFORE_CONFIGURATION';
    // SaaS.4: the tenant had a plan, none covers this date, and it has no configuration of its own on it.
    case NoPlanInForce = 'NO_PLAN_IN_FORCE';
    case ModuleUnknown = 'MODULE_UNKNOWN';
    case LimitNotConfigured = 'LIMIT_NOT_CONFIGURED';
    case UsageUnavailable = 'USAGE_UNAVAILABLE';
    case NoTenantContext = 'NO_TENANT_CONTEXT';
    case EvaluationFailed = 'EVALUATION_FAILED';
    case ShadowDisabled = 'SHADOW_DISABLED';
    // NOT_APPLICABLE
    case NotCommercial = 'NOT_COMMERCIAL';
}
