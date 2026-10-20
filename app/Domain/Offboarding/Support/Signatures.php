<?php

namespace App\Domain\Offboarding\Support;

/**
 * SaaS.9: HMAC-SHA256 signatures of export manifests and deletion certificates, with a key derived from the application
 * key for that purpose only. Anyone holding the application key (Markedge) can verify that a manifest or certificate is
 * the one PeopleOS produced; the canonical JSON is what is signed.
 */
final class Signatures
{
    public static function canonical(array $document): string
    {
        return json_encode(self::sortKeys($document), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function sign(string $purpose, array $document): string
    {
        return hash_hmac('sha256', self::canonical($document), self::key($purpose));
    }

    public static function verify(string $purpose, array $document, string $signature): bool
    {
        return hash_equals(self::sign($purpose, $document), $signature);
    }

    /** SaaS.12: also accepts a signature made before an application key rotation (`APP_PREVIOUS_KEYS`). */
    public static function verifyWithAnyKey(string $purpose, array $document, string $signature): bool
    {
        foreach ([(string) config('app.key'), ...array_map('strval', (array) config('app.previous_keys', []))] as $appKey) {
            if ($appKey !== '' && hash_equals(hash_hmac('sha256', self::canonical($document), self::key($purpose, $appKey)), $signature)) {
                return true;
            }
        }

        return false;
    }

    private static function key(string $purpose, ?string $appKey = null): string
    {
        $appKey ??= (string) config('app.key');
        if (str_starts_with($appKey, 'base64:')) {
            $appKey = (string) base64_decode(substr($appKey, 7));
        }

        return hash_hmac('sha256', 'peopleos-saas9-'.$purpose, $appKey, true);
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($v) => self::sortKeys($v), $value);
    }
}
