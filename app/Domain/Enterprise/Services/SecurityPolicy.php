<?php

namespace App\Domain\Enterprise\Services;

use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Str;

/** Tenant security policy (§82, §110): IP allowlist, password rules, session idle timeout, MFA requirement. */
final class SecurityPolicy
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function ipAllowed(?string $ip): bool
    {
        $list = array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $this->settings->get('security.ip_allowlist', ''))));
        if ($list === [] || $ip === null) {
            return true;
        }
        foreach ($list as $entry) {
            if ($this->ipMatches($ip, $entry)) {
                return true;
            }
        }

        return false;
    }

    /** Exact IP, wildcard suffix (10.0.*) or CIDR (10.0.0.0/8). IPv4 only for CIDR. */
    public function ipMatches(string $ip, string $entry): bool
    {
        if ($entry === $ip) {
            return true;
        }
        if (str_ends_with($entry, '*')) {
            return str_starts_with($ip, rtrim($entry, '*'));
        }
        if (str_contains($entry, '/') && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            [$subnet, $bits] = explode('/', $entry, 2);
            $bits = (int) $bits;
            if (! filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $bits < 0 || $bits > 32) {
                return false;
            }
            $mask = $bits === 0 ? 0 : (-1 << (32 - $bits)) & 0xFFFFFFFF;

            return (ip2long($ip) & $mask) === (ip2long($subnet) & $mask);
        }

        return false;
    }

    /** @return array<int, string> validation problems, empty when the password complies */
    public function passwordProblems(string $password): array
    {
        $problems = [];
        $min = (int) $this->settings->get('security.password_min_length', 10);
        if (Str::length($password) < $min) {
            $problems[] = "Use at least {$min} characters.";
        }
        if (! preg_match('/[A-Z]/', $password) || ! preg_match('/[a-z]/', $password)) {
            $problems[] = 'Mix upper- and lower-case letters.';
        }
        if (! preg_match('/\d/', $password)) {
            $problems[] = 'Include a number.';
        }

        return $problems;
    }

    public function passwordExpired(?\DateTimeInterface $changedAt): bool
    {
        $days = (int) $this->settings->get('security.password_expiry_days', 0);

        return $days > 0 && ($changedAt === null || $changedAt < now()->subDays($days));
    }

    public function idleMinutes(): int
    {
        return (int) $this->settings->get('security.session_idle_minutes', 0);
    }

    public function mfaRequired(): bool
    {
        return (bool) $this->settings->get('security.mfa_required', false);
    }
}
