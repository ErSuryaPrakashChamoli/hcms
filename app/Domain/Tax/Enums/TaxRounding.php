<?php

namespace App\Domain\Tax\Enums;

use Brick\Math\RoundingMode;

/** SaaS.7: rounding of a tax amount to the currency's minor unit, as the verified rule states it. */
enum TaxRounding: string
{
    case HalfUp = 'half_up';
    case HalfEven = 'half_even';

    public function mode(): RoundingMode
    {
        return match ($this) {
            self::HalfUp => RoundingMode::HalfUp,
            self::HalfEven => RoundingMode::HalfEven,
        };
    }
}
