<?php

namespace App\Domain\Billing\Enums;

/**
 * SaaS.7: what a price's unit amount is multiplied by. Both are representable; which plans use which is decision
 * B-1, and per-employee billing also needs the billable quantity definition (B-2). Neither is applied by SaaS.7.
 */
enum PricingBasis: string
{
    case Flat = 'flat';
    case PerActiveEmployee = 'per_active_employee';

    public function label(): string
    {
        return match ($this) {
            self::Flat => 'flat',
            self::PerActiveEmployee => 'per active employee',
        };
    }
}
