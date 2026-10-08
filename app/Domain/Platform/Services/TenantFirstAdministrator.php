<?php

namespace App\Domain\Platform\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserInvitation;
use App\Domain\Identity\Services\UserInvitations;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Support\LifecycleRefused;
use App\Domain\Platform\Support\PlatformOperator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * SaaS.8: the invitation of a new tenant's first administrator, sent by the platform.
 *
 * Provisioning commits the tenant and then e-mails the invitation. If that e-mail failed, the exception escaped after the
 * commit: the operator saw an error for a tenant that existed, a retry collided on the slug, and the administrator was
 * left `invited` with no way in, except through the database. Now:
 * - The invitation row, its audit event and the e-mail are one unit: if the e-mail is not accepted by the mail transport,
 *   no pending invitation is left behind that nobody received (the transaction rolls back).
 * - A failure is recorded on both chains (INVITATION_FAILED) and the tenant stays provisioned; the Tenants list then
 *   offers "Send invitation" to an operator, with a reason.
 * - Sending locks the administrator's row, so two operators sending at once issue one invitation after the other, and
 *   a second send within the throttle window is refused instead of e-mailing two links.
 */
final class TenantFirstAdministrator
{
    public function __construct(
        private readonly UserInvitations $invitations,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $tenants,
    ) {}

    /** The tenant's first super administrator who has not yet accepted an invitation, if any. */
    public function invitee(Tenant $tenant): ?User
    {
        return $this->tenants->runAs($tenant, fn () => User::query()->where('tenant_id', $tenant->getKey())->where('status', UserStatus::Invited->value)
            ->whereHas('roles', fn ($q) => $q->where('slug', 'tenant-super-admin'))->orderBy('id')->first());
    }

    /** True when the invited first administrator holds no invitation they could still accept. */
    public function needsInvitation(Tenant $tenant): bool
    {
        $invitee = $this->invitee($tenant);

        return $invitee !== null && ! $this->tenants->runAs($tenant, fn () => UserInvitation::query()->where('user_id', $invitee->getKey())
            ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->exists());
    }

    /** Provisioning's step: never throws for a mail failure; records it instead. Returns whether the e-mail went out. */
    public function inviteAfterProvisioning(Tenant $tenant, User $invitee, ?User $actor): bool
    {
        try {
            DB::transaction(fn () => $this->invitations->issue($invitee, $actor, 'First administrator of a new tenant'));

            return true;
        } catch (Throwable $e) {
            report($e);
            $this->recordFailure($tenant, $invitee, $actor, $e);

            return false;
        }
    }

    /** An operator sends (or sends again) the first administrator's invitation. */
    public function send(Tenant $tenant, ?User $actor, string $reason): UserInvitation
    {
        $actor = PlatformOperator::assert($actor, "send a tenant's first-administrator invitation");
        $reason = PlatformOperator::reason($reason);
        $invitee = $this->invitee($tenant) ?? throw new LifecycleRefused('This tenant has no first administrator waiting for an invitation.');
        if (! $tenant->fresh()?->isAccessible()) {
            throw new LifecycleRefused('Invitations cannot be sent while the tenant is suspended.');
        }
        $throttle = max(0, (int) config('peopleos.identity.invitation_resend_seconds', 60));

        try {
            $invitation = DB::transaction(function () use ($tenant, $invitee, $actor, $reason, $throttle) {
                $locked = User::query()->whereKey($invitee->getKey())->lockForUpdate()->first();
                if ($locked === null || $locked->status !== UserStatus::Invited) {
                    throw new LifecycleRefused('The first administrator has already accepted their invitation.');
                }
                $recent = $throttle > 0 && $this->tenants->runAs($tenant, fn () => UserInvitation::query()->where('user_id', $locked->getKey())
                    ->where('created_at', '>', now()->subSeconds($throttle))->exists());
                if ($recent) {
                    throw new LifecycleRefused('An invitation was sent moments ago. Wait a minute before sending another.');
                }

                return $this->invitations->issue($locked, $actor, $reason);
            }); // one attempt: a retry after a deadlock could e-mail a second link
        } catch (LifecycleRefused $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            $this->recordFailure($tenant, $invitee, $actor, $e);

            throw new RuntimeException('The invitation e-mail could not be sent. Nothing was issued; try again later.', previous: $e);
        }

        $this->audit->record(AuditAction::InvitationIssued, 'platform', null, [], $reason, entityLabel: "Tenant {$tenant->name}", actor: $actor,
            metadata: ['subject_tenant_id' => $tenant->getKey(), 'subject_tenant' => $tenant->slug, 'user_id' => $invitee->getKey(), 'first_administrator' => true], platform: true);

        return $invitation;
    }

    private function recordFailure(Tenant $tenant, User $invitee, ?User $actor, Throwable $e): void
    {
        $metadata = ['subject_tenant_id' => $tenant->getKey(), 'subject_tenant' => $tenant->slug, 'user_id' => $invitee->getKey(), 'error' => class_basename($e)];
        $this->audit->record(AuditAction::InvitationFailed, 'identity', $invitee, [], 'The invitation e-mail was not sent', tenantId: $tenant->getKey(), actor: $actor, metadata: $metadata);
        $this->audit->record(AuditAction::InvitationFailed, 'platform', null, [], 'The invitation e-mail was not sent', entityLabel: "Tenant {$tenant->name}", actor: $actor, metadata: $metadata, platform: true);
    }
}
