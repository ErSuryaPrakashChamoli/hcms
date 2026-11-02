<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\TemporaryPasswordMail;
use App\Domain\Platform\Services\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use SensitiveParameter;

/**
 * Owner decision (2026-10-31): a person's first sign-in (or a fresh start for an existing user) is a generated temporary
 * password instead of an invitation link. It is either e-mailed to the person (the invitation) or shown once to the
 * administrator to hand over; the person signs in with it and must choose their own password before anything else
 * (EnforceAccountSecurity → ChangeExpiredPassword).
 *
 * - Generated to the tenant's password rules; only its hash is stored, and it is never logged, audited or queued (the
 *   e-mail is sent directly). The administrator sees it only when they ask for it to be shown, once.
 * - Valid for peopleos.identity.invitation_hours; an expired one no longer signs in (User::canAccessPanel, inside the
 *   login timebox, so it fails like a wrong password).
 * - Replaces the current password: a pending invitation link is revoked and, for an active account, every session ends.
 * - An invited account stays invited until the person chooses their own password, then becomes active. Choosing it
 *   confirms the address: an e-mailed temporary password proves the mailbox (as an invitation link does), and one shown
 *   to an administrator was handed over by them to that person (owner decision 2026-11-01: nobody is sent to "Verify
 *   your email address" after replacing a temporary password).
 * - A suspended account, a platform operator and a suspended organisation get none.
 */
final class TemporaryPasswords
{
    public const VIA = ['email', 'shown'];

    /** The shortest it is made (longer when the tenant's minimum is). */
    private const LENGTH = 14;

    /** Upper and lower case, digits and symbols, without look-alikes (0/O, 1/l/I), so it can be read out or typed by hand. */
    private const ALPHABET = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!@#$%&*?+='];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AuditRecorder $audit,
        private readonly PasswordPolicy $policy,
        private readonly UserInvitations $invitations,
        private readonly SessionSecurity $sessions,
        private readonly SettingsRepository $settings,
    ) {}

    /** E-mails the person a new temporary password: the invitation for an invited user, a fresh start for an active one. */
    public function email(User $user, ?User $by = null, ?string $reason = null): void
    {
        $invited = $user->status === UserStatus::Invited;
        $password = $this->issue($user, 'email', $by, $reason);
        $user->notify(new TemporaryPasswordMail($password, $user->tenant()->value('name') ?? 'PeopleOS', $user->temporary_password_until, Filament::getPanel('admin')->getLoginUrl(), $invited));
    }

    /** A new temporary password for the administrator to hand over; returned once and never kept. */
    public function show(User $user, ?User $by = null, ?string $reason = null): string
    {
        return $this->issue($user, 'shown', $by, $reason);
    }

    /** Whether the user can be given one (otherwise why not). */
    public function refusal(User $user): ?string
    {
        return match (true) {
            $user->tenant_id === null || $user->isPlatformAdmin() => 'Only a user of an organisation can be given a temporary password.',
            ! in_array($user->status, [UserStatus::Invited, UserStatus::Active], true) => 'A suspended user cannot be given a temporary password. Activate the user first.',
            ! (bool) $user->tenant()->first()?->isAccessible() => 'Temporary passwords cannot be given while the organisation is suspended.',
            default => null,
        };
    }

    /**
     * The person replaces their temporary password with their own (ChangeExpiredPassword): an invited account becomes
     * active, and the address counts as confirmed, whether the temporary password was e-mailed or handed over.
     */
    public function replace(User $user, #[SensitiveParameter] string $password): void
    {
        $user->forceFill([
            'password' => $password,
            'status' => $user->status === UserStatus::Invited ? UserStatus::Active : $user->status,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->withAuditReason('Temporary password replaced')->save();
    }

    /** Revoke invitation: an invited user's temporary password stops working at once. */
    public function withdraw(User $user, ?User $by, string $reason): void
    {
        if (! $user->mustReplacePassword()) {
            return;
        }
        // A password nobody knows; the saving hook ends the temporary one.
        $user->forceFill(['password' => Str::random(64)])->withAuditReason($reason)->save();
        $this->audit->record(AuditAction::InvitationRevoked, 'identity', $user, [], $reason, tenantId: $user->tenant_id, actor: $by, metadata: ['temporary_password' => true]);
    }

    private function issue(User $user, string $via, ?User $by, ?string $reason): string
    {
        if (($refusal = $this->refusal($user)) !== null) {
            throw new RuntimeException($refusal);
        }
        $tenant = $user->tenant()->firstOrFail();
        $password = $this->tenants->runAs($tenant, fn () => $this->generate($user));
        $until = now()->addHours(max(1, (int) config('peopleos.identity.invitation_hours', 72)));
        $active = $user->status === UserStatus::Active;

        $this->tenants->runAs($tenant, fn () => DB::transaction(function () use ($user, $by, $via, $until, $password, $active, $reason) {
            $this->invitations->revokePendingFor($user, $by, 'superseded by a temporary password');
            $user->giveTemporaryPassword($password, $until, $via)->withAuditReason($reason ?? 'Temporary password')->save();
            if ($active) {
                $this->sessions->revokeUser($user, 'Temporary password given', $by, 'temporary_password');
            }
        }));

        $this->audit->record(AuditAction::TemporaryPasswordIssued, 'identity', $user, [], $reason, tenantId: $tenant->getKey(), actor: $by,
            metadata: ['via' => $via, 'expires_at' => $until->toIso8601String()]);

        return $password;
    }

    /** A password the tenant's rules accept (random, so personal details and recent passwords never match). */
    private function generate(User $user): string
    {
        $all = implode('', self::ALPHABET);
        $length = max(self::LENGTH, (int) $this->settings->get('security.password_min_length', 10));
        for ($attempt = 0; $attempt < 5; $attempt++) {
            // One of each kind, then any, shuffled with the secure randomiser.
            $chars = array_map(fn (string $set) => $set[random_int(0, strlen($set) - 1)], self::ALPHABET);
            while (count($chars) < $length) {
                $chars[] = $all[random_int(0, strlen($all) - 1)];
            }
            $password = implode('', Arr::shuffle($chars));
            if ($this->policy->problemsFor($user, $password) === []) {
                return $password;
            }
        }

        throw new RuntimeException('A temporary password could not be generated under the password rules. Check Security → Password policy.');
    }
}
