<?php

namespace App\Domain\Billing\Enums;

/** SaaS.7: intervals a price can be expressed in. Representable, not offered: which ones are sold is decision B-3. */
enum BillingInterval: string
{
    case Month = 'month';
    case Year = 'year';

    public function label(): string
    {
        return match ($this) {
            self::Month => 'per month',
            self::Year => 'per year',
        };
    }
}
