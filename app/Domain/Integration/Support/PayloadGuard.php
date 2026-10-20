<?php

namespace App\Domain\Integration\Support;

/**
 * Phase 14: strips secrets and highly sensitive personal / financial fields from any payload that
 * leaves PeopleOS (outbound webhooks) or is stored as integration metadata, recursively, by key
 * name. A key-name guard, not the primary control: payloads are built from allow-listed references
 * in the first place (WebhookEventBridge).
 */
final class PayloadGuard
{
    public const DENIED_KEYS = [
        'password', 'secret', 'token', 'api_key', 'apikey', 'authorization', 'access_token', 'refresh_token', 'client_secret', 'private_key',
        'pan', 'uan', 'aadhaar', 'aadhaar_reference', 'esic_number', 'pf_number', 'ip_number', 'account_number', 'bank_account', 'ifsc',
        'ctc', 'ctc_annual', 'salary', 'gross', 'net', 'net_pay', 'basic', 'component_values', 'amount_annual',
        'private_notes', 'confidential_notes', 'internal_notes', 'resolution_notes',
    ];

    /** @param  array<mixed>  $payload */
    public static function clean(array $payload): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::DENIED_KEYS, true)) {
                continue;
            }
            $out[$key] = is_array($value) ? self::clean($value) : $value;
        }

        return $out;
    }

    /** Top-level key names only (for stored metadata; never values). @return list<string> */
    public static function keys(array $payload): array
    {
        return array_values(array_map('strval', array_keys($payload)));
    }
}
