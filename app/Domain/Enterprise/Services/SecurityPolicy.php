<?php

namespace App\Domain\Enterprise\Services;

use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Platform\Services\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Tenant security policy (§82, §110): IP allowlist, password rules, session idle timeout, MFA requirement.
 * HROne setup parity (Security → Password policy, Disable login): a strict password can also need a symbol and avoid
 * the person's own name, e-mail and employee code; expiry is enforced (EnforceAccountSecurity) with reminders before
 * it; recent passwords can be refused (PasswordPolicy); lifecycle states can turn sign-in off (DisableLoginOnStatus).
 */
final class SecurityPolicy
{
    /**
     * Lifecycle states that may turn an employee's sign-in off (HROne: other statuses such as suspended, sabbatical,
     * retired). The working states (joined, probation, confirmed, active) are not offered, so one setting can never
     * lock the whole workforce out.
     */
    public const LOGIN_DISABLE_STATES = ['pre_employee', 'preboarding', 'onboarding', 'on_leave', 'suspended', 'notice_period', 'exited', 'alumni'];

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

    /**
     * @param  list<string>  $personal  words the password may not contain when security.password_block_personal is on
     *                                  (the person's names, e-mail name, employee code); shorter than 3 characters are ignored
     * @return array<int, string> validation problems, empty when the password complies
     */
    public function passwordProblems(string $password, array $personal = []): array
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
        if ($this->requiresSymbol() && ! preg_match('/[^A-Za-z0-9]/', $password)) {
            $problems[] = 'Include a symbol (for example ! # % or @).';
        }
        if ($this->blocksPersonalDetails()) {
            $lower = Str::lower($password);
            foreach ($personal as $word) {
                $word = Str::lower(trim($word));
                if (Str::length($word) >= 3 && str_contains($lower, $word)) {
                    $problems[] = 'Do not use your name, e-mail or employee code in the password.';
                    break;
                }
            }
        }

        return $problems;
    }

    public function blocksPersonalDetails(): bool
    {
        return (bool) $this->settings->get('security.password_block_personal', false);
    }

    public function requiresSymbol(): bool
    {
        return (bool) $this->settings->get('security.password_require_symbol', false);
    }

    public function passwordExpired(?\DateTimeInterface $changedAt): bool
    {
        $days = $this->expiryDays();

        return $days > 0 && ($changedAt === null || $changedAt < now()->subDays($days));
    }

    public function expiryDays(): int
    {
        return max(0, (int) $this->settings->get('security.password_expiry_days', 0));
    }

    /** When a password changed at $changedAt expires (null when passwords never expire or the date is unknown). */
    public function passwordExpiresAt(?\DateTimeInterface $changedAt): ?CarbonImmutable
    {
        $days = $this->expiryDays();

        return $days > 0 && $changedAt !== null ? CarbonImmutable::instance($changedAt)->addDays($days) : null;
    }

    /** Days before expiry at which the person is reminded (0 = no reminder). */
    public function expiryWarningDays(): int
    {
        return max(0, (int) $this->settings->get('security.password_expiry_warning_days', 7));
    }

    /** How many recent passwords may not be chosen again (0 = no restriction). */
    public function passwordHistoryCount(): int
    {
        return min(24, max(0, (int) $this->settings->get('security.password_history_count', 0)));
    }

    /** @return list<string> lifecycle states in which an employee's sign-in is turned off */
    public function loginDisabledStates(): array
    {
        return array_values(array_intersect(array_map('strval', (array) $this->settings->get('security.disable_login_states', [])), self::LOGIN_DISABLE_STATES));
    }

    /** @return array<string, string> the states that may turn sign-in off, with their labels */
    public static function loginDisableOptions(): array
    {
        return collect(self::LOGIN_DISABLE_STATES)->mapWithKeys(fn (string $state) => [$state => LifecycleState::from($state)->getLabel()])->all();
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
