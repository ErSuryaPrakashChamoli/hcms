<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Enterprise\Services\SecurityPolicy;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Session\Session;
use RuntimeException;

/**
 * SaaS.2: multi-factor authentication, decided on every request rather than once at route registration.
 *
 * - Who must use it: a tenant user when their tenant's `security.mfa_required` is on; a platform
 *   operator when `peopleos.security.platform_mfa_required` is on (default on). An operator inside a
 *   tenant follows the platform rule, never the tenant's.
 * - Whether this session has proved it: a session is verified only after a code (or recovery code) was
 *   accepted in it: at the password login challenge, on the challenge page (remember-me and SSO
 *   sessions) or by setting up an authenticator. Every new login starts unverified.
 * - Every change of MFA state is audited, never with a secret, code or recovery code.
 */
final class MultiFactor
{
    public const SESSION_KEY = 'auth.mfa_verified';

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly SecurityPolicy $policy,
        private readonly AuditRecorder $audit,
        private readonly SessionSecurity $sessions,
    ) {}

    public function requiredFor(User $user): bool
    {
        if ($user->isPlatformAdmin()) {
            return (bool) config('peopleos.security.platform_mfa_required', true);
        }
        if ($user->tenant_id === null) {
            return false;
        }

        return $this->tenants->id() === $user->tenant_id
            ? $this->policy->mfaRequired()
            : (bool) $this->tenants->runAs($user->tenant()->firstOrFail(), fn () => $this->policy->mfaRequired());
    }

    public function isVerified(Session $session, User $user): bool
    {
        return (string) $session->get(self::SESSION_KEY) === (string) $user->getKey();
    }

    public function markVerified(Session $session, User $user): void
    {
        $session->put(self::SESSION_KEY, $user->getKey());
    }

    public function forget(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
    }

    /** Called by User when the authenticator secret is saved (set-up) or cleared (removed by its owner). */
    public function secretChanged(User $user, bool $enabled): void
    {
        $this->audit->record(AuditAction::MfaChanged, 'identity', $user, [['field' => 'multi_factor', 'before' => $enabled ? 'off' : 'on', 'after' => $enabled ? 'on' : 'off']], null, tenantId: $user->tenant_id, metadata: ['event' => $enabled ? 'enabled' : 'disabled', 'method' => 'app']);

        // Setting up an authenticator proves possession of it, so the session that did it is verified.
        if ($enabled && request()->hasSession() && auth()->id() === $user->getKey()) {
            $this->markVerified(request()->session(), $user);
        }
    }

    /** Called by User when recovery codes change after set-up: one was used, or the set was regenerated. */
    public function recoveryCodesChanged(User $user, ?array $before, ?array $after): void
    {
        if ($before === null || $after === null) {
            return; // set-up and removal are audited with the secret
        }
        $event = count($after) === count($before) - 1 ? 'recovery_code_used' : 'recovery_codes_regenerated';

        $this->audit->record(AuditAction::MfaChanged, 'identity', $user, [], null, tenantId: $user->tenant_id, metadata: ['event' => $event, 'remaining' => count($after)]);
    }

    /**
     * An administrator removes a user's authenticator (lost device). The user's sessions end everywhere;
     * at the next sign-in they set up a new authenticator if their tenant requires one.
     */
    public function reset(User $user, string $reason, ?User $actor): void
    {
        if ($actor !== null && $actor->is($user)) {
            throw new RuntimeException('Use your profile to change your own authenticator.');
        }
        if ($actor !== null && ! $actor->isPlatformAdmin() && $actor->tenant_id !== $user->tenant_id) {
            throw new RuntimeException('This user belongs to another organisation.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('A reason is required.');
        }

        $user->forceFill(['app_authentication_secret' => null, 'app_authentication_recovery_codes' => null])->save();
        $this->audit->record(AuditAction::MfaChanged, 'identity', $user, [['field' => 'multi_factor', 'before' => 'on', 'after' => 'off']], $reason, tenantId: $user->tenant_id, actor: $actor, metadata: ['event' => 'reset_by_administrator']);
        $this->sessions->revokeUser($user, $reason, $actor, 'mfa_reset');
    }
}
