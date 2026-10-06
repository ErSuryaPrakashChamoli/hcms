<?php

namespace App\Domain\Entitlements\Enums;

/**
 * SaaS.3: whether a capability may ever be enforced, and how carefully. Nothing is enforced in SaaS.3.
 * - NotCommercial: never an entitlement (the HCM core and every security control); always NOT_APPLICABLE.
 * - Protected: commercial, but statutory or lifecycle-critical (payroll, onboarding, exit). Enforcement needs a
 *   separately approved rollout that never interrupts work already started.
 * - Eligible: commercial, and may be enforced in a later, staged rollout.
 */
enum EnforcementClass: string
{
    case NotCommercial = 'not_commercial';
    case Protected = 'protected';
    case Eligible = 'eligible';
}
