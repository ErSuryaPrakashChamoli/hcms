<?php

namespace App\Domain\Billing\Enums;

/**
 * SaaS.7: how a price is billed (B-3, approved): monthly in arrears on the month's peak, or annually in advance on a
 * committed quantity with monthly true-up in arrears. Both anchor on calendar months.
 */
enum BillingInterval: string
{
    case Month = 'month';
    case Year = 'year';

    public function label(): string
    {
        return match ($this) {
            self::Month => 'billed monthly in arrears',
            self::Year => 'billed annually in advance',
        };
    }
}
