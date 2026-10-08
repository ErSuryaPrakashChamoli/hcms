<?php

namespace App\Support\Observability;

use App\Support\Tenancy\TenantContext;
use Illuminate\Log\Logger as IlluminateLogger;
use Monolog\Logger;
use Monolog\LogRecord;

/**
 * Phase 14 log hygiene: a Monolog processor applied (as a channel tap) to every file / stream channel.
 *
 * - Redacts context and extra values whose key names a secret or protected identifier (passwords,
 *   tokens, API secrets, keys, authorization, bank account / IFSC, PAN, UAN, Aadhaar, statutory
 *   numbers, salary and pay amounts, confidential notes).
 * - Redacts recognisable values anywhere in the message or context: bearer / API / webhook keys,
 *   PAN, Aadhaar-like numbers, long account-like digit runs.
 * - Adds the bound tenant id (the request / correlation id already arrives through Laravel Context).
 */
final class RedactSensitiveLogData
{
    public const MASK = '[redacted]';

    private const KEYS = '/(pass(word|code)?|secret|token|api[_-]?key|private[_-]?key|authorization|cookie|signature|credential|bank|account[_-]?number|ifsc|(?:^|[_.-])(?:pan|uan)(?:$|[_.-])|aadhaar|esic|pf_number|registration_number|salary|ctc|gross|net_pay|amount|employer_cost|confidential|note_body|answer_text)/i';

    private const VALUES = [
        '/\b(?:Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{8,}/i',
        '/\b(?:pk|sk|whsec|sk-ant)[_-][A-Za-z0-9_\-]{8,}/',
        '/\beyJ[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{5,}/',
        '/\b[A-Z]{5}[0-9]{4}[A-Z]\b/',
        '/\b[0-9]{4}\s?[0-9]{4}\s?[0-9]{4}\b/',
        '/\b[0-9]{11,18}\b/',
    ];

    /** Channel tap: `'tap' => [RedactSensitiveLogData::class]`. */
    public function __invoke(IlluminateLogger $logger): void
    {
        $monolog = $logger->getLogger();
        if ($monolog instanceof Logger) {
            $monolog->pushProcessor($this->process(...));
        }
    }

    public function process(LogRecord $record): LogRecord
    {
        $extra = $record->extra;
        $tenantId = app()->bound(TenantContext::class) ? app(TenantContext::class)->id() : null;
        if ($tenantId !== null) {
            $extra['tenant_id'] = $tenantId;
        }

        return $record->with(message: self::scrub($record->message), context: self::clean($record->context), extra: self::clean($extra));
    }

    public static function scrub(string $text): string
    {
        return (string) preg_replace(self::VALUES, self::MASK, $text);
    }

    /** @param  array<mixed>  $data */
    public static function clean(array $data, int $depth = 0): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::KEYS, $key) === 1) {
                $data[$key] = self::MASK;
            } elseif (is_array($value) && $depth < 5) {
                $data[$key] = self::clean($value, $depth + 1);
            } elseif (is_string($value)) {
                $data[$key] = self::scrub($value);
            }
        }

        return $data;
    }
}
