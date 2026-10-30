<?php

namespace App\Domain\Identity\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Enterprise\Services\SecurityPolicy;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * SaaS.2: the tenant's password policy (security.password_min_length plus the complexity rules in
 * SecurityPolicy) applied wherever a person chooses a password: accepting an invitation, resetting a
 * forgotten password and changing it on the profile. Platform operators, who have no tenant, need at
 * least 12 characters on top of the default rules.
 *
 * HROne setup parity: a tenant user's password must also meet the tenant's symbol and personal-details rules and may
 * not be one of their recent passwords when the tenant restricts reuse (PasswordHistory).
 */
final class PasswordPolicy
{
    public const PLATFORM_MIN_LENGTH = 12;

    public function __construct(private readonly TenantContext $tenants, private readonly SecurityPolicy $policy, private readonly PasswordHistory $history) {}

    /** @return list<string> */
    public function problemsFor(User $user, #[SensitiveParameter] string $password): array
    {
        if ($user->tenant_id === null) {
            $problems = $this->policy->passwordProblems($password);
            if (Str::length($password) < self::PLATFORM_MIN_LENGTH) {
                array_unshift($problems, 'Use at least '.self::PLATFORM_MIN_LENGTH.' characters.');
            }

            return array_values(array_unique($problems));
        }

        return $this->tenants->id() === $user->tenant_id
            ? $this->tenantProblems($user, $password)
            : $this->tenants->runAs($user->tenant()->firstOrFail(), fn () => $this->tenantProblems($user, $password));
    }

    /** @return list<string> */
    private function tenantProblems(User $user, #[SensitiveParameter] string $password): array
    {
        $problems = $this->policy->passwordProblems($password, $this->personalWords($user));
        if ($this->history->wasRecentlyUsed($user, $password)) {
            $count = $this->policy->passwordHistoryCount();
            $problems[] = $count === 1 ? 'Choose a password different from your current one.' : "Choose a password you have not used for your last {$count} passwords.";
        }

        return array_values($problems);
    }

    /** @return list<string> the person's names, e-mail name and employee codes (only read when the rule is on) */
    private function personalWords(User $user): array
    {
        if (! $this->policy->blocksPersonalDetails()) {
            return [];
        }
        $email = (string) ($user->getAttributes()['email'] ?? '');
        $codes = $user->getKey() === null ? [] : Employee::query()->withoutGlobalScope(AccessScope::class)->where('user_id', $user->getKey())->pluck('employee_code')->all();

        return array_values(array_filter([...preg_split('/[\s.\-_]+/', (string) ($user->getAttributes()['name'] ?? '')) ?: [], Str::before($email, '@'), ...$codes], fn ($w) => is_string($w) && $w !== ''));
    }

    /** @throws ValidationException */
    public function assertAcceptable(User $user, #[SensitiveParameter] string $password, string $field = 'password'): void
    {
        $problems = $this->problemsFor($user, $password);
        if ($problems !== []) {
            throw ValidationException::withMessages([$field => implode(' ', $problems)]);
        }
    }
}
