<?php

namespace App\Domain\Integration\Support;

/**
 * Phase 14: the one HMAC scheme for signed webhooks, inbound and outbound:
 * `X-PeopleOS-Timestamp: <unix seconds>`, `X-PeopleOS-Signature: sha256=<hex HMAC-SHA256(secret, "timestamp.body")>`.
 * Verification is constant-time and refuses timestamps outside the tolerance window (replay window).
 */
final class Signature
{
    public static function sign(string $secret, string $timestamp, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /** @return 'valid'|'invalid_signature'|'stale_timestamp' */
    public static function check(string $secret, ?string $timestamp, string $body, ?string $signature, int $toleranceSeconds = 300, ?int $now = null): string
    {
        if ($timestamp === null || $signature === null || ! ctype_digit($timestamp)) {
            return 'invalid_signature';
        }
        if (abs(($now ?? now()->getTimestamp()) - (int) $timestamp) > $toleranceSeconds) {
            return 'stale_timestamp';
        }

        return hash_equals(self::sign($secret, $timestamp, $body), $signature) ? 'valid' : 'invalid_signature';
    }
}
