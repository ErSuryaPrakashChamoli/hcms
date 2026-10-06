<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Contracts\Session\Session;

/**
 * SaaS.2: ends sessions without touching the session store.
 *
 * - Every tenant and every user carries a session epoch (an integer).
 * - A session remembers the epochs it was opened under: stamp() runs on every login, including a
 *   remember-me login and SSO.
 * - EnsureSessionIsValid signs out a request whose stamp no longer matches.
 * - Raising a tenant's epoch ends all of its users' sessions (suspension). Raising a user's epoch ends
 *   that user's sessions everywhere (MFA reset, account suspension, password reset, "sign out everywhere").
 * - Remember-me tokens are cleared at the same time, so a stolen remember cookie cannot open a new,
 *   correctly stamped session.
 *
 * Works with any session driver. The check costs no query: the user and their tenant are already
 * loaded on every request. Epochs are read leniently (a database that has not run the SaaS.2 migration
 * reads 0), so the code is safe during a deployment window.
 */
final class SessionSecurity
{
    public const SESSION_KEY = 'auth.session_epoch';

    public function __construct(private readonly AuditRecorder $audit) {}

    public function fingerprint(User $user): string
    {
        $tenantEpoch = 0;
        if ($user->tenant_id !== null) {
            $tenant = $user->relationLoaded('tenant') ? $user->tenant : $user->tenant()->first();
            $tenantEpoch = (int) ($tenant?->getAttributes()['session_epoch'] ?? 0);
        }

        return $user->getKey().':'.(int) ($user->getAttributes()['session_epoch'] ?? 0).':'.$tenantEpoch;
    }

    public function stamp(Session $session, User $user): void
    {
        $session->put(self::SESSION_KEY, $this->fingerprint($user));
    }

    /**
     * Whether the session was opened under the current epochs. A session opened before SaaS.2 carries
     * no stamp; it is adopted once (suspended tenants and inactive users are refused separately). A stamp
     * for another user can only come from a sign-in that did not pass through the Login event (tests
     * switching users); it is treated the same way, since epochs only ever compare one user with itself.
     */
    public function isCurrent(Session $session, User $user): bool
    {
        $stamp = $session->get(self::SESSION_KEY);
        if ($stamp === null || ! str_starts_with((string) $stamp, $user->getKey().':')) {
            $this->stamp($session, $user);

            return true;
        }

        return hash_equals($this->fingerprint($user), (string) $stamp);
    }

    /** Ends every session of one user, on every device. */
    public function revokeUser(User $user, string $reason, ?User $actor = null, string $cause = 'revoked'): void
    {
        User::query()->whereKey($user->getKey())->increment('session_epoch', 1, ['remember_token' => null]);
        $user->forceFill(['session_epoch' => (int) ($user->getAttributes()['session_epoch'] ?? 0) + 1, 'remember_token' => null])->syncOriginal();

        $this->audit->record(AuditAction::SessionsRevoked, 'identity', $user, [], $reason, tenantId: $user->tenant_id, actor: $actor, metadata: ['cause' => $cause]);
    }

    /** Ends every session of every user of one tenant (the caller audits the reason, e.g. a suspension). */
    public function revokeTenant(Tenant $tenant): void
    {
        Tenant::query()->whereKey($tenant->getKey())->increment('session_epoch');
        User::query()->where('tenant_id', $tenant->getKey())->whereNotNull('remember_token')->update(['remember_token' => null]);
        $tenant->forceFill(['session_epoch' => (int) ($tenant->getAttributes()['session_epoch'] ?? 0) + 1])->syncOriginal();
    }
}
