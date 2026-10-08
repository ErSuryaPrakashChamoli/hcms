<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserInvitation;
use App\Domain\Identity\Notifications\InvitationLink;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use SensitiveParameter;

/**
 * SaaS.2: the invitation lifecycle. An administrator never chooses or sees a user's password.
 *
 * tenant admin → invite (user created as `invited`, no usable password) → e-mail with a one-time link →
 * the invitee chooses a password (tenant policy) → user `active`, e-mail verified → normal sign-in
 * (MFA set-up follows if the tenant requires it).
 *
 * - Tokens: 64 random characters, only the SHA-256 is stored, valid for peopleos.identity.invitation_hours.
 * - One-time: acceptance locks the invitation row, then the user row; a second acceptance finds it used.
 * - Revocable: issuing a new invitation, an administrator revoking it, or changing the invitee's e-mail
 *   revokes the pending one.
 * - Tenant- and user-bound: the invitation names one user of one tenant; it carries no role or
 *   permission (roles are assigned by the administrator through the normal, authorised user form).
 * - Every failure says the same thing, so a link reveals nothing about why it does not work.
 * - Distinct from password reset: an invitation activates an `invited` account and works for nobody else;
 *   a reset link is only ever sent to an `active` account.
 */
final class UserInvitations
{
    public const INVALID = 'This invitation link is invalid or has expired. Ask your administrator to send a new one.';

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AuditRecorder $audit,
        private readonly PasswordPolicy $passwords,
    ) {}

    /** Issues a new invitation for an invited user (revoking any pending one) and e-mails the link. */
    public function issue(User $user, ?User $by = null, ?string $reason = null): UserInvitation
    {
        if ($user->tenant_id === null || $user->is_platform_admin || $user->status !== UserStatus::Invited) {
            throw new RuntimeException('Only an invited user of an organisation can be sent an invitation.');
        }
        $tenant = $user->tenant()->firstOrFail();
        if (! $tenant->isAccessible()) {
            throw new RuntimeException('Invitations cannot be sent while the organisation is suspended.');
        }

        $token = Str::random(64);
        $invitation = $this->tenants->runAs($tenant, fn () => DB::transaction(function () use ($user, $by, $token) {
            $this->revokePendingFor($user, $by, 'superseded by a new invitation');

            return UserInvitation::query()->create([
                'user_id' => $user->getKey(),
                'token_hash' => hash('sha256', $token),
                'invited_by' => $by?->getKey(),
                'expires_at' => now()->addHours(max(1, (int) config('peopleos.identity.invitation_hours', 72))),
            ]);
        }));

        $this->audit->record(AuditAction::InvitationIssued, 'identity', $user, [], $reason, tenantId: $tenant->getKey(), actor: $by, metadata: ['invitation_id' => $invitation->getKey(), 'expires_at' => $invitation->expires_at->toIso8601String()]);
        $user->notify(new InvitationLink(route('filament.admin.auth.invitation.accept', ['token' => $token]), $tenant->name, $invitation->expires_at));

        return $invitation;
    }

    /** The pending invitation for a presented token, or null (looked up before any tenant is known). */
    public function pending(#[SensitiveParameter] string $token): ?UserInvitation
    {
        $invitation = $this->find($token);
        if ($invitation === null || ! $invitation->isPending()) {
            return null;
        }
        $user = User::query()->find($invitation->user_id);

        return $user !== null && $this->accepts($invitation, $user) ? $invitation->setRelation('user', $user) : null;
    }

    /**
     * Activates the invited account with the chosen password. Throws InvalidInvitation (one message for
     * every reason) or a ValidationException for a password the tenant policy refuses.
     */
    public function accept(#[SensitiveParameter] string $token, #[SensitiveParameter] string $password): User
    {
        return DB::transaction(function () use ($token, $password): User {
            $invitation = $this->find($token, lock: true);
            if ($invitation === null || ! $invitation->isPending()) {
                throw new InvalidInvitation(self::INVALID);
            }
            $user = User::query()->whereKey($invitation->user_id)->lockForUpdate()->first();
            if ($user === null || ! $this->accepts($invitation, $user)) {
                throw new InvalidInvitation(self::INVALID);
            }
            $this->passwords->assertAcceptable($user, $password);

            $tenant = $user->tenant()->firstOrFail();
            $this->tenants->runAs($tenant, function () use ($user, $invitation, $password): void {
                $user->forceFill(['password' => $password, 'status' => UserStatus::Active, 'email_verified_at' => now()])
                    ->withAuditReason('Invitation accepted')->save();
                $invitation->forceFill(['accepted_at' => now()])->save();
            });
            $this->audit->record(AuditAction::InvitationAccepted, 'identity', $user, [], null, tenantId: $tenant->getKey(), actor: $user, metadata: ['invitation_id' => $invitation->getKey()]);

            return $user;
        });
    }

    public function revoke(UserInvitation $invitation, ?User $by, string $reason): void
    {
        if ($invitation->accepted_at !== null || $invitation->revoked_at !== null) {
            return;
        }
        $invitation->forceFill(['revoked_at' => now(), 'revoked_reason' => Str::limit($reason, 255, '')])->save();
        $user = User::query()->find($invitation->user_id);
        $this->audit->record(AuditAction::InvitationRevoked, 'identity', $user, [], $reason, tenantId: $invitation->tenant_id, actor: $by, metadata: ['invitation_id' => $invitation->getKey()]);
    }

    /** Revokes every pending invitation of a user (new invitation, e-mail change, account removed). */
    public function revokePendingFor(User $user, ?User $by, string $reason): int
    {
        $pending = UserInvitation::query()->where('user_id', $user->getKey())->whereNull('accepted_at')->whereNull('revoked_at')->get();
        $pending->each(fn (UserInvitation $invitation) => $this->revoke($invitation, $by, $reason));

        return $pending->count();
    }

    public function latestFor(User $user): ?UserInvitation
    {
        return UserInvitation::query()->where('user_id', $user->getKey())->latest('id')->first();
    }

    /** The invitation must still name this user, in this tenant, who is still waiting and can sign in once active. */
    private function accepts(UserInvitation $invitation, User $user): bool
    {
        return $user->tenant_id !== null
            && $user->tenant_id === $invitation->tenant_id
            && ! $user->is_platform_admin
            && $user->status === UserStatus::Invited
            && ($tenant = $user->tenant()->first()) !== null
            && $tenant->isAccessible();
    }

    /** Token lookup crosses tenants by design: the invitee is not signed in and no tenant is bound yet. */
    private function find(string $token, bool $lock = false): ?UserInvitation
    {
        if (Str::length($token) !== 64) {
            return null;
        }

        return $this->tenants->bypass(fn () => UserInvitation::query()->where('token_hash', hash('sha256', $token))
            ->when($lock, fn ($q) => $q->lockForUpdate())->first());
    }
}
