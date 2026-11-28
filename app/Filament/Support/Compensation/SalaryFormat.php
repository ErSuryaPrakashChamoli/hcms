<?php

namespace App\Filament\Support\Compensation;

use NumberFormatter;

/**
 * How salary amounts read on screen (owner, 2026-11-27: "make every detail readable"): the currency's own grouping and
 * symbol (₹48,00,000 in lakhs and crores for INR, $4,800,000 otherwise), paise or cents only when there are any, and
 * percentages without trailing zeros (+10%, +9.5%). Display only — amounts are stored and compared unrounded.
 */
final class SalaryFormat
{
    public static function money(?float $amount, ?string $currency = 'INR', bool $symbol = true): string
    {
        if ($amount === null) {
            return '—';
        }
        $currency = strtoupper((string) ($currency ?: 'INR'));
        $fraction = abs($amount - round($amount)) >= 0.005 ? 2 : 0;
        $formatter = new NumberFormatter($currency === 'INR' ? 'en_IN' : 'en', $symbol ? NumberFormatter::CURRENCY : NumberFormatter::DECIMAL);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $fraction);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $fraction);
        $text = $symbol ? $formatter->formatCurrency($amount, $currency) : $formatter->format($amount);

        return $text === false ? $currency.' '.number_format($amount, $fraction) : $text;
    }

    /** "+₹2,000" / "−₹1,600" / "₹0". */
    public static function signed(?float $amount, ?string $currency = 'INR', bool $symbol = true): string
    {
        if ($amount === null) {
            return '—';
        }

        return ($amount > 0.004 ? '+' : ($amount < -0.004 ? '−' : '')).self::money(abs($amount), $currency, $symbol);
    }

    /** "+10%", "−2.5%", "0%" (two decimals at most, no trailing zeros). */
    public static function percent(?float $percent, bool $signed = true): string
    {
        if ($percent === null) {
            return '—';
        }
        $text = rtrim(rtrim(number_format(abs($percent), 2, '.', ','), '0'), '.');

        return ($signed && $percent > 0.004 ? '+' : ($percent < -0.004 ? '−' : '')).$text.'%';
    }
}
