<?php

namespace App\Domain\Billing\Enums;

/**
 * SaaS.7: what a price's unit amount is multiplied by. B-1 (approved): per employee per month (PEPM), on the billable
 * quantity of B-2 (the monthly peak employed count), with an optional minimum quantity; the unit amount is per month
 * whatever the billing interval. Flat stays representable for a later decision; the billing run does not bill it.
 * SaaS.7 configuration closure: a flat (fixed) amount is per billing interval (a month, or a year for annual terms)
 * and is billed: a fixed annual commitment of 600,000 is entered as 600,000.
 */
enum PricingBasis: string
{
    case Flat = 'flat';
    case PerActiveEmployee = 'per_active_employee';

    public function label(): string
    {
        return match ($this) {
            self::Flat => 'fixed per billing interval',
            self::PerActiveEmployee => 'per employee per month',
        };
    }
}
