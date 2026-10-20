<?php

namespace App\Support\Money;

use NumberFormatter;

/**
 * SaaS.7: locale-aware display of an amount (₹1,00,000.00, $1,250.00, 1.250,00 €, £1,250.00, ¥1,250). Display only:
 * the locale never decides or changes the stored currency. Always shows exactly the currency's minor units. ICU
 * formats binary floats, so amounts beyond what a double holds exactly fall back to "CODE 1234.56" (never a
 * rounded or wrong figure).
 */
final class MoneyFormatter
{
    /** Major-unit magnitude below which a double reproduces every minor unit exactly (well under 2^53 minor units). */
    private const EXACT_MINOR_LIMIT = 1_000_000_000_000_000;

    public static function format(Money $money, string $locale = 'en'): string
    {
        if (abs($money->minor) >= self::EXACT_MINOR_LIMIT) {
            return "{$money->currency->value} {$money->toDecimal()}";
        }
        $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $money->currency->minorUnits());
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $money->currency->minorUnits());
        $formatted = $formatter->formatCurrency((float) $money->toDecimal(), $money->currency->value);

        return $formatted === false ? "{$money->currency->value} {$money->toDecimal()}" : $formatted;
    }
}
