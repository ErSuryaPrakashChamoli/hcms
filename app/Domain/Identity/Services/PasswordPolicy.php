<?php

namespace App\Domain\Identity\Services;

use App\Domain\Enterprise\Services\SecurityPolicy;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * SaaS.2: the tenant's password policy (security.password_min_length plus the complexity rules in
 * SecurityPolicy) applied wherever a person chooses a password: accepting an invitation, resetting a
 * forgotten password and changing it on the profile. Platform operators, who have no tenant, need at
 * least 12 characters on top of the default rules.
 */
final class PasswordPolicy
{
    public const PLATFORM_MIN_LENGTH = 12;

    public function __construct(private readonly TenantContext $tenants, private readonly SecurityPolicy $policy) {}

    /** @return list<string> */
    public function problemsFor(User $user, string $password): array
    {
        if ($user->tenant_id === null) {
            $problems = $this->policy->passwordProblems($password);
            if (Str::length($password) < self::PLATFORM_MIN_LENGTH) {
                array_unshift($problems, 'Use at least '.self::PLATFORM_MIN_LENGTH.' characters.');
            }

            return array_values(array_unique($problems));
        }

        return $this->tenants->id() === $user->tenant_id
            ? $this->policy->passwordProblems($password)
            : $this->tenants->runAs($user->tenant()->firstOrFail(), fn () => $this->policy->passwordProblems($password));
    }

    /** @throws ValidationException */
    public function assertAcceptable(User $user, string $password, string $field = 'password'): void
    {
        $problems = $this->problemsFor($user, $password);
        if ($problems !== []) {
            throw ValidationException::withMessages([$field => implode(' ', $problems)]);
        }
    }
}
