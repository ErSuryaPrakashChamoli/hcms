<?php

namespace App\Domain\Integration\Support;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * HROne parity CA-11: the per-key IP allowlist. Entries are exact IPv4 / IPv6 addresses or CIDR blocks (10.0.0.0/8,
 * 2001:db8::/32). An empty list means "any address" (the behaviour before CA-11).
 *
 * Fails closed: entries are validated when a key is saved (invalid() names the bad ones, and saving is refused), and at
 * request time a malformed entry that somehow got stored never matches anything — it can only narrow access, never widen it.
 */
final class IpAllowlist
{
    public const MAX_ENTRIES = 100;

    /**
     * Splits free text (commas, spaces, new lines) or a list into trimmed, de-duplicated entries.
     *
     * @param  string|array<int, mixed>|null  $input
     * @return list<string>
     */
    public static function normalise(string|array|null $input): array
    {
        $parts = is_array($input) ? $input : (preg_split('/[\s,;]+/', (string) $input) ?: []);

        return array_values(array_unique(array_filter(array_map(fn ($p) => trim((string) $p), $parts), fn (string $p) => $p !== '')));
    }

    /**
     * The entries that are not an IP address or a CIDR block.
     *
     * @param  list<string>  $entries
     * @return list<string>
     */
    public static function invalid(array $entries): array
    {
        return array_values(array_filter($entries, fn (string $entry) => ! self::isValidEntry($entry)));
    }

    public static function isValidEntry(string $entry): bool
    {
        if (! str_contains($entry, '/')) {
            return filter_var($entry, FILTER_VALIDATE_IP) !== false;
        }
        [$address, $bits] = explode('/', $entry, 2);
        if (! ctype_digit($bits)) {
            return false;
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return (int) $bits <= 32;
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return (int) $bits <= 128;
        }

        return false;
    }

    /**
     * Whether the address may use a key with these entries. Empty = any address; no address = refused when a list exists.
     *
     * @param  list<string>|null  $entries
     */
    public static function allows(?array $entries, ?string $ip): bool
    {
        $entries = self::normalise($entries ?? []);
        if ($entries === []) {
            return true;
        }
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $valid = array_values(array_filter($entries, fn (string $entry) => self::isValidEntry($entry)));

        return $valid !== [] && IpUtils::checkIp($ip, $valid);
    }
}
