<?php

namespace App\Domain\Tax\Jurisdictions\India;

use App\Domain\Tax\Contracts\TaxIdValidator;
use App\Domain\Tax\Enums\TaxIdType;
use InvalidArgumentException;

/**
 * SaaS.7 India GST: GSTIN format check. 15 characters: the GST state code, a PAN, an entity number, "Z" and a
 * check character (Luhn mod 36). The state code must be a known one and, when a subdivision is given, match it.
 * This is a format check only; the GSTIN is not looked up on the GST portal.
 */
final class GstinValidator implements TaxIdValidator
{
    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    public function type(): TaxIdType
    {
        return TaxIdType::InGstin;
    }

    public function validate(string $value, ?string $subdivision): string
    {
        $gstin = strtoupper(trim($value));
        if (preg_match('/^(\d{2})[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $gstin, $m) !== 1) {
            throw new InvalidArgumentException("{$value} is not a GSTIN (15 characters: state code, PAN, entity number, Z, check character).");
        }
        if (! GstStates::isKnownCode($m[1])) {
            throw new InvalidArgumentException("{$gstin} starts with {$m[1]}, which is not a GST state code.");
        }
        if (self::checkCharacter(substr($gstin, 0, 14)) !== $gstin[14]) {
            throw new InvalidArgumentException("{$gstin} fails the GSTIN check character.");
        }
        if ($subdivision !== null && GstStates::code($subdivision) !== $m[1]) {
            $state = GstStates::name($subdivision) ?? $subdivision;
            throw new InvalidArgumentException("{$gstin} is registered in state code {$m[1]}, not in {$state}.");
        }

        return $gstin;
    }

    public static function checkCharacter(string $first14): string
    {
        $factor = 2;
        $sum = 0;
        for ($i = strlen($first14) - 1; $i >= 0; $i--) {
            $product = $factor * strpos(self::ALPHABET, $first14[$i]);
            $factor = $factor === 2 ? 1 : 2;
            $sum += intdiv($product, 36) + ($product % 36);
        }

        return self::ALPHABET[(36 - ($sum % 36)) % 36];
    }
}
