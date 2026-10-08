<?php

namespace App\Domain\Ai\Services;

use App\Domain\Platform\Services\SettingsRepository;

/**
 * Phase 14: the AI data boundary (ADR-0016). Every fact, question and draft answer is classified before
 * it can leave PeopleOS for an external language model:
 *
 * - prohibited: passwords, API keys and secrets, tokens, encryption / private keys, signatures. Never
 *   sent, never stored in the AI log, under any tenant setting.
 * - restricted: bank / statutory identifiers, salary and other amounts, ratings, grievance, health and
 *   personal contact data. Sent only when the tenant's ai.external_data_policy is "restricted".
 * - allowed: everything else.
 *
 * The tenant policy "none" keeps every answer deterministic: nothing is sent at all.
 * Classification is by fact key (snake-case segments) and by value pattern, so a PAN pasted into a
 * question is caught as well as a field called "pan".
 */
final class AiDataPolicy
{
    public const ALLOWED = 'allowed';

    public const RESTRICTED = 'restricted';

    public const PROHIBITED = 'prohibited';

    public const REMOVED = '[removed]';

    public const REDACTED = '[redacted]';

    public function __construct(private readonly SettingsRepository $settings) {}

    /** The tenant's external data policy: none | allowed | restricted. Unknown values fail closed to "none". */
    public function tenantPolicy(): string
    {
        $policy = (string) $this->settings->get('ai.external_data_policy', 'allowed');

        return array_key_exists($policy, config('peopleos.ai.data_policy.policies', [])) ? $policy : 'none';
    }

    public function classifyKey(string $key): string
    {
        $normalised = '_'.strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+|(?<=[a-z])(?=[A-Z])/', '_', $key), '_')).'_';
        foreach ([self::PROHIBITED => 'prohibited_keys', self::RESTRICTED => 'restricted_keys'] as $class => $list) {
            foreach (config("peopleos.ai.data_policy.{$list}", []) as $token) {
                if (str_contains($normalised, "_{$token}_") || str_contains($normalised, "_{$token}s_")) {
                    return $class;
                }
            }
        }

        return self::ALLOWED;
    }

    public function classifyText(string $text): string
    {
        foreach ([self::PROHIBITED => 'prohibited_values', self::RESTRICTED => 'restricted_values'] as $class => $list) {
            foreach (config("peopleos.ai.data_policy.{$list}", []) as $pattern) {
                if (preg_match($pattern, $text) === 1) {
                    return $class;
                }
            }
        }

        return self::ALLOWED;
    }

    /**
     * Facts prepared for the external provider under the tenant policy.
     *
     * @param  array<string, mixed>  $facts
     * @return array{facts: array<string, mixed>, removed: int, redacted: int}
     */
    public function prepare(array $facts, ?string $policy = null): array
    {
        $policy ??= $this->tenantPolicy();
        $stats = ['removed' => 0, 'redacted' => 0];
        $clean = $this->walk($facts, $policy, $stats);

        return ['facts' => $clean, ...$stats];
    }

    /**
     * Free text (a question or a deterministic draft) with prohibited values removed and, unless the
     * policy is "restricted", restricted values redacted.
     *
     * @return array{text: string, removed: int, redacted: int}
     */
    public function redactText(string $text, ?string $policy = null): array
    {
        $policy ??= $this->tenantPolicy();
        $removed = 0;
        $redacted = 0;
        foreach (config('peopleos.ai.data_policy.prohibited_values', []) as $pattern) {
            $text = (string) preg_replace($pattern, self::REMOVED, $text, -1, $n);
            $removed += $n;
        }
        if ($policy !== 'restricted') {
            foreach (config('peopleos.ai.data_policy.restricted_values', []) as $pattern) {
                $text = (string) preg_replace($pattern, self::REDACTED, $text, -1, $n);
                $redacted += $n;
            }
        }

        return compact('text', 'removed', 'redacted');
    }

    /** Text safe to keep in the AI log: prohibited values removed whatever the policy. */
    public function forLog(string $text): string
    {
        foreach (config('peopleos.ai.data_policy.prohibited_values', []) as $pattern) {
            $text = (string) preg_replace($pattern, self::REMOVED, $text);
        }

        return $text;
    }

    private function walk(array $facts, string $policy, array &$stats): array
    {
        $out = [];
        foreach ($facts as $key => $value) {
            $class = is_string($key) ? $this->classifyKey($key) : self::ALLOWED;
            if ($class === self::PROHIBITED) {
                $stats['removed']++;

                continue;
            }
            if ($class === self::RESTRICTED && $policy !== 'restricted') {
                $out[$key] = self::REDACTED;
                $stats['redacted']++;

                continue;
            }
            if (is_array($value)) {
                $out[$key] = $this->walk($value, $policy, $stats);
            } elseif (is_string($value)) {
                $r = $this->redactText($value, $policy);
                $stats['removed'] += $r['removed'];
                $stats['redacted'] += $r['redacted'];
                $out[$key] = $r['text'];
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
