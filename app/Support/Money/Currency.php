<?php

namespace App\Support\Money;

use InvalidArgumentException;

/**
 * SaaS.7: the controlled ISO-4217 currency catalogue for commercial billing. Each currency carries its minor units
 * (0, 2 or 3 decimals), the only place precision is decided. Being listed here is a technical fact, never market
 * support: a currency is sold only through a billing market an operator creates. Codes are stored; symbols are
 * display (intl), never data.
 */
enum Currency: string
{
    case INR = 'INR';
    case USD = 'USD';
    case EUR = 'EUR';
    case GBP = 'GBP';
    case AED = 'AED';
    case SGD = 'SGD';
    case AUD = 'AUD';
    case CAD = 'CAD';
    case CHF = 'CHF';
    case JPY = 'JPY';
    case BHD = 'BHD';
    case KWD = 'KWD';

    public static function of(self|string $currency): self
    {
        if ($currency instanceof self) {
            return $currency;
        }

        return self::tryFrom($currency) ?? throw new InvalidArgumentException("{$currency} is not a currency in the PeopleOS catalogue (ISO 4217 code expected).");
    }

    /** Decimal places of the currency's minor unit (ISO 4217). */
    public function minorUnits(): int
    {
        return match ($this) {
            self::JPY => 0,
            self::BHD, self::KWD => 3,
            default => 2,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::INR => 'Indian rupee',
            self::USD => 'US dollar',
            self::EUR => 'Euro',
            self::GBP => 'Pound sterling',
            self::AED => 'UAE dirham',
            self::SGD => 'Singapore dollar',
            self::AUD => 'Australian dollar',
            self::CAD => 'Canadian dollar',
            self::CHF => 'Swiss franc',
            self::JPY => 'Japanese yen',
            self::BHD => 'Bahraini dinar',
            self::KWD => 'Kuwaiti dinar',
        };
    }

    /** @return array<string, string> code => "CODE · name", for operator selects */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $currency) {
            $options[$currency->value] = "{$currency->value} · {$currency->label()} ({$currency->minorUnits()} decimals)";
        }

        return $options;
    }
}
