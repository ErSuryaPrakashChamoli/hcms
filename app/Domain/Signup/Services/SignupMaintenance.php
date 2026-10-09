<?php

namespace App\Domain\Signup\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Services\TenantFirstAdministrator;
use App\Domain\Signup\Enums\SignupStatus;
use App\Domain\Signup\Models\SignupApplication;
use App\Domain\Signup\Support\SignupAudit;
use App\Domain\Signup\Support\SignupTransitions;
use Illuminate\Support\Facades\DB;

/**
 * SaaS.10: the signup tick (`peopleos:signups:tick`, every minute, one server, no overlap). Each step is bounded per
 * run and safe to repeat:
 * 1. expire applications whose link (email_pending) or completion window (details_pending) has passed: kept, never
 *    deleted;
 * 2. recover runs interrupted between claim and commit (lease passed): nothing of them was kept, so they are retried;
 * 3. provision approved applications and failed ones whose backoff is over;
 * 4. send the owner's invitation again when the mail transport refused it (limited attempts), or note that an operator
 *    already sent it;
 * 5. mark applications active once their owner has chosen a password, and remove the contact's name and address from
 *    the application (the account in the tenant holds them from then on).
 * It runs as the console: provisioning here names no operator (PlatformOperator).
 */
final class SignupMaintenance
{
    private const BATCH = 100;

    public function __construct(
        private readonly SignupProvisioning $provisioning,
        private readonly SignupTransitions $transitions,
        private readonly SignupAudit $audit,
    ) {}

    /** @return array{expired: int, recovered: int, provisioned: int, failed: int, invitations: int, activated: int} */
    public function run(): array
    {
        return ['expired' => $this->expire(), 'recovered' => $this->recoverInterrupted()] + $this->provisionDue()
            + ['invitations' => $this->retryInvitations(), 'activated' => $this->reconcileActivation()];
    }

    public function expire(): int
    {
        $count = 0;
        $due = SignupApplication::query()
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('status', SignupStatus::EmailPending->value)->where('token_expires_at', '<=', now()))
                ->orWhere(fn ($q) => $q->where('status', SignupStatus::DetailsPending->value)->where('expires_at', '<=', now())))
            ->orderBy('id')->limit(self::BATCH)->get();
        foreach ($due as $application) {
            $from = $application->status;
            if ($this->transitions->move($application, SignupStatus::Expired, ['token_hash' => null, 'token_expires_at' => null], [$from])) {
                $this->audit->record(AuditAction::SignupExpired, $application, $from->value, null, $from === SignupStatus::EmailPending
                    ? 'The address was not confirmed in time' : 'The details were not completed in time');
                $count++;
            }
        }

        return $count;
    }

    public function recoverInterrupted(): int
    {
        $count = 0;
        $lease = (int) config('peopleos.signup.provisioning.lease_minutes', 15);
        $stale = SignupApplication::query()->where('status', SignupStatus::Provisioning->value)->where('claimed_at', '<=', now()->subMinutes($lease))
            ->whereNull('provisioned_tenant_id')->orderBy('id')->limit(self::BATCH)->get();
        foreach ($stale as $application) {
            $manual = $application->attempts >= (int) config('peopleos.signup.provisioning.max_attempts', 5);
            $to = $manual ? SignupStatus::FailedManualReview : SignupStatus::FailedRetryable;
            $moved = $this->transitions->move($application, $to, ['claim_token' => null, 'failure_code' => 'INTERRUPTED', 'failed_at' => now(),
                'failure_summary' => 'The set-up stopped before it finished; nothing of it was kept.'.($manual ? ' Attempts are exhausted: an operator decides.' : ' It is retried automatically.'),
                'next_attempt_at' => $manual ? null : now()], [SignupStatus::Provisioning], (string) $application->claim_token);
            if ($moved) {
                $this->audit->record(AuditAction::SignupProvisioningFailed, $application, SignupStatus::Provisioning->value, null, null, ['code' => 'INTERRUPTED']);
                $manual && $this->audit->record(AuditAction::SignupManualReview, $application, SignupStatus::Provisioning->value, null, 'Attempts exhausted', ['code' => 'INTERRUPTED']);
                $count++;
            }
        }

        return $count;
    }

    /** @return array{provisioned: int, failed: int} */
    public function provisionDue(): array
    {
        $result = ['provisioned' => 0, 'failed' => 0];
        $ids = SignupApplication::query()
            ->where(fn ($q) => $q->where('status', SignupStatus::Approved->value)
                ->orWhere(fn ($q) => $q->where('status', SignupStatus::FailedRetryable->value)->where('next_attempt_at', '<=', now())))
            ->orderBy('id')->limit(20)->pluck('id');
        foreach ($ids as $id) {
            $application = SignupApplication::query()->find($id);
            if ($application === null) {
                continue;
            }
            $tenant = $this->provisioning->provision($application);
            $tenant !== null ? $result['provisioned']++ : $result['failed'] += (int) in_array($application->fresh()->status, [SignupStatus::FailedRetryable, SignupStatus::FailedManualReview], true);
        }

        return $result;
    }

    public function retryInvitations(): int
    {
        $count = 0;
        $max = (int) config('peopleos.signup.provisioning.invitation_attempts', 5);
        $pending = SignupApplication::query()->with('provisionedTenant')->where('status', SignupStatus::ActivationPending->value)->whereNull('invitation_sent_at')
            ->where('invitation_failures', '<', $max)
            ->where(fn ($q) => $q->whereNull('invitation_attempted_at')->orWhere('invitation_attempted_at', '<=', now()->subMinutes(5)))
            ->orderBy('id')->limit(self::BATCH)->get();
        foreach ($pending as $application) {
            $tenant = $application->provisionedTenant;
            $owner = $application->provisioned_user_id ? User::query()->find($application->provisioned_user_id) : null;
            if ($tenant === null || $owner === null || $owner->status !== UserStatus::Invited || ! $tenant->isAccessible()) {
                continue;
            }
            if (! app(TenantFirstAdministrator::class)->needsInvitation($tenant)) {
                // Sent meanwhile (an operator, from the Tenants list): only the application did not know.
                $application->forceFill(['invitation_sent_at' => now()])->save();

                continue;
            }
            $this->provisioning->invite($application, $tenant, $owner) && $count++;
        }

        return $count;
    }

    public function reconcileActivation(): int
    {
        $count = 0;
        $activated = SignupApplication::query()->where('status', SignupStatus::ActivationPending->value)
            ->whereIn('provisioned_user_id', User::query()->select('id')->where('status', UserStatus::Active->value))
            ->orderBy('id')->limit(self::BATCH)->get();
        foreach ($activated as $application) {
            $moved = DB::transaction(fn () => $this->transitions->move($application, SignupStatus::Active,
                ['activated_at' => now(), 'email' => null, 'email_hash' => null, 'contact_name' => null], [SignupStatus::ActivationPending]));
            if ($moved) {
                $this->audit->record(AuditAction::SignupActivated, $application, SignupStatus::ActivationPending->value, null,
                    'The account owner chose a password; the contact details were removed from the application', tenantChain: true);
                $count++;
            }
        }

        return $count;
    }
}
