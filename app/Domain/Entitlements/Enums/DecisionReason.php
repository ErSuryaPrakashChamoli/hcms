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
    // UNKNOWN
    case TenantUnconfigured = 'TENANT_UNCONFIGURED';
    case BeforeConfiguration = 'BEFORE_CONFIGURATION';
    case ModuleUnknown = 'MODULE_UNKNOWN';
    case LimitNotConfigured = 'LIMIT_NOT_CONFIGURED';
    case UsageUnavailable = 'USAGE_UNAVAILABLE';
    case NoTenantContext = 'NO_TENANT_CONTEXT';
    case EvaluationFailed = 'EVALUATION_FAILED';
    case ShadowDisabled = 'SHADOW_DISABLED';
    // NOT_APPLICABLE
    case NotCommercial = 'NOT_COMMERCIAL';
}
