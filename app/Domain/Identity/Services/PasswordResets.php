<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\PasswordResetLink;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * SaaS.2: forgotten-password reset.
 *
 * - Requests are silent. Whatever the address, the caller learns nothing: an unknown address, a
 *   suspended account, an invited user (who must use their invitation), a suspended tenant and a
 *   throttled request all look the same. The e-mail and its audit event are sent after the response
 *   (defer), so an existing account is not slower to answer.
 * - Tokens are Laravel's password-broker tokens: random, stored hashed, valid for
 *   auth.passwords.users.expire minutes, one per user, deleted when used.
 * - Completion runs under a lock on the user row, so two submissions of one link cannot both succeed.
 *   It applies the tenant password policy, proves the mailbox (e-mail verified), rotates the remember
 *   token and ends every existing session of the user.
 * - Multi-factor authentication is never touched: the next sign-in still asks for the code.
 */
final class PasswordResets
{
    public const INVALID = 'This password reset link is invalid or has expired. Request a new one.';

    public function __construct(
        private readonly PasswordPolicy $policy,
        private readonly SessionSecurity $sessions,
        private readonly AuditRecorder $audit,
    ) {}

    public function request(string $email): void
    {
        Password::broker()->sendResetLink(['email' => Str::lower(trim($email))], function (User $user, #[SensitiveParameter] string $token): void {
            if (! $this->mayReset($user)) {
                return;
            }
            $notification = new PasswordResetLink(Filament::getResetPasswordUrl($token, $user), (int) config('auth.passwords.users.expire', 60));

            defer(function () use ($user, $notification): void {
                $user->notify($notification);
                $this->audit->record(AuditAction::PasswordResetRequested, 'identity', $user, [], null, tenantId: $user->tenant_id, metadata: ['channel' => 'email']);
            });
        });
    }

    /** @return bool true when the password was changed; false for any invalid link (never says which part was wrong) */
    public function reset(string $email, #[SensitiveParameter] string $token, #[SensitiveParameter] string $password): bool
    {
        return DB::transaction(function () use ($email, $token, $password): bool {
            $user = User::query()->where('email', Str::lower(trim($email)))->lockForUpdate()->first();
            // The link is proved first: a policy message for an unproved link would reveal that the address exists.
            if ($user === null || ! $this->mayReset($user) || ! Password::broker()->tokenExists($user, $token)) {
                return false;
            }
            $this->policy->assertAcceptable($user, $password);

            $status = Password::broker()->reset(
                ['email' => $user->email, 'token' => $token, 'password' => $password],
                function (User $user, #[SensitiveParameter] string $password): void {
                    $user->forceFill(['password' => $password, 'email_verified_at' => $user->email_verified_at ?? now()])->save();
                    $this->sessions->revokeUser($user, 'Password reset by the account holder', $user, 'password_reset');
                    event(new PasswordReset($user)); // audited as PASSWORD_CHANGED by RecordAuthenticationEvents
                },
            );

            return $status === Password::PASSWORD_RESET;
        });
    }

    /** Only someone who could sign in may reset: an active account in an accessible tenant (or an operator). */
    public function mayReset(User $user): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        return $user->isPlatformAdmin() || ($user->tenant !== null && $user->tenant->isAccessible());
    }
}
