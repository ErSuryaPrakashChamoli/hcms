<?php

namespace App\Domain\Entitlements\Enums;

/** SaaS.3: the four answers the evaluator can give. None of them blocks anything in shadow mode. */
enum DecisionOutcome: string
{
    case Allow = 'ALLOW';
    case Deny = 'DENY';
    case Unknown = 'UNKNOWN';
    case NotApplicable = 'NOT_APPLICABLE';
}
