<?php

namespace App\Domain\Compliance\Support;

use RuntimeException;

/**
 * HROne setup parity CA-2: checks a statutory registration number against the format its registration type declares in
 * peopleos.compliance.registration_types (data, not code): `pattern` (a regular expression, explained by `format_hint`),
 * `check` (`luhn_mod36`: the last character is the Luhn mod 36 check character of the others) and `state_prefixes` (per
 * state, the leading codes a number registered there starts with).
 */
final class RegistrationNumberFormat
{
    private const BASE36 = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /** @param  array<string, mixed>  $type */
    public static function assertValid(array $type, string $number, ?string $stateCode): void
    {
        $label = (string) ($type['label'] ?? 'The registration');
        if (isset($type['pattern']) && preg_match($type['pattern'], $number) !== 1) {
            throw new RuntimeException("{$label}: the number has the wrong format (".($type['format_hint'] ?? 'see the registration certificate').').');
        }
        if (($type['check'] ?? null) === 'luhn_mod36' && substr($number, -1) !== self::luhnMod36(substr($number, 0, -1))) {
            throw new RuntimeException("{$label}: the check character does not match; check the number for a typing mistake.");
        }
        $prefixes = (array) (($type['state_prefixes'] ?? [])[strtoupper((string) $stateCode)] ?? []);
        if ($stateCode !== null && $prefixes !== [] && ! in_array(substr($number, 0, strlen((string) $prefixes[0])), $prefixes, true)) {
            throw new RuntimeException("{$label}: the number starts with ".substr($number, 0, strlen((string) $prefixes[0])).', which is not the code of the registration\'s state ('.implode(' or ', $prefixes).').');
        }
    }

    /** The Luhn mod 36 check character of a string of digits and capital letters. */
    public static function luhnMod36(string $body): string
    {
        $sum = 0;
        $factor = 2;
        for ($i = strlen($body) - 1; $i >= 0; $i--) {
            $product = $factor * strpos(self::BASE36, $body[$i]);
            $factor = $factor === 2 ? 1 : 2;
            $sum += intdiv($product, 36) + $product % 36;
        }

        return self::BASE36[(36 - $sum % 36) % 36];
    }
}
