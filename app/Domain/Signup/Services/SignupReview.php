<?php

namespace App\Domain\Signup\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Services\TenantFirstAdministrator;
use App\Domain\Platform\Support\PlatformOperator;
use App\Domain\Signup\Enums\SignupStatus;
use App\Domain\Signup\Models\SignupApplication;
use App\Domain\Signup\Notifications\SignupUpdate;
use App\Domain\Signup\Support\SignupAudit;
use App\Domain\Signup\Support\SignupMailer;
use App\Domain\Signup\Support\SignupRefused;
use App\Domain\Signup\Support\SignupTransitions;
use Illuminate\Support\Str;

/**
 * SaaS.10: what a platform operator does with an application. Every action is refused here unless a platform operator
 * names a reason (never only hidden on the page), is a conditional transition, and is audited with the operator.
 * An applicant can never act here: signup creates no operator and no tenant user before provisioning, and applicant
 * steps go through SignupApplications only. Approving does what operator provisioning already may (a tenant and a
 * trial); it grants nothing beyond the accepted offer.
 */
final class SignupReview
{
    public function __construct(
        private readonly SignupEligibility $eligibility,
        private readonly SignupProvisioning $provisioning,
        private readonly SignupApplications $applications,
        private readonly SignupTransitions $transitions,
        private readonly SignupAudit $audit,
        private readonly SignupMailer $mailer,
    ) {}

    /** Approves a reviewed application (eligibility re-checked first), then provisions it at once as this operator. */
    public function approve(SignupApplication $application, ?User $operator, string $reason): ?Tenant
    {
        [$operator, $reason] = $this->guard($operator, $reason, 'approve a signup application');
        $application = $application->fresh();
        if ($application->status !== SignupStatus::ReviewPending) {
            throw new SignupRefused('NOT_IN_REVIEW', 'Only an application in review can be approved.');
        }
        $this->eligibility->assertProvisionable($application);
        if (! $this->transitions->move($application, SignupStatus::Approved, ['decided_by' => $operator->id, 'decided_at' => now(), 'decision_reason' => $reason], [SignupStatus::ReviewPending])) {
            throw new SignupRefused('NOT_IN_REVIEW', 'This application changed meanwhile; reload it.');
        }
        $this->audit->record(AuditAction::SignupApproved, $application, SignupStatus::ReviewPending->value, $operator, $reason);

        return $this->provisioning->provision($application, $operator, $reason);
    }

    /** Rejects an application in review, or one whose set-up failed (no tenant exists then: the set-up rolled back). */
    public function reject(SignupApplication $application, ?User $operator, string $reason): SignupApplication
    {
        [$operator, $reason] = $this->guard($operator, $reason, 'reject a signup application');
        $application = $application->fresh();
        $from = $application->status;
        if (! in_array($from, [SignupStatus::ReviewPending, SignupStatus::FailedManualReview], true) || $application->provisioned_tenant_id !== null
            || ! $this->transitions->move($application, SignupStatus::Rejected, ['decided_by' => $operator->id, 'decided_at' => now(), 'decision_reason' => $reason,
                'token_hash' => null, 'token_expires_at' => null], [$from])) {
            throw new SignupRefused('NOT_REJECTABLE', 'Only an application in review, or one whose set-up needs attention, can be rejected.');
        }
        $this->audit->record(AuditAction::SignupRejected, $application, $from->value, $operator, $reason);
        $this->mailer->send((string) $application->email, new SignupUpdate('rejected', $application->reference, (string) ($application->display_name ?? $application->company_name)),
            'rejected', $application, $operator);

        return $application;
    }

    /** Stops automatic retries of a failed set-up so a person decides (retry, reject or cancel). */
    public function holdForReview(SignupApplication $application, ?User $operator, string $reason): SignupApplication
    {
        [$operator, $reason] = $this->guard($operator, $reason, 'hold a signup application for review');
        $application = $application->fresh();
        if (! $this->transitions->move($application, SignupStatus::FailedManualReview, ['next_attempt_at' => null], [SignupStatus::FailedRetryable])) {
            throw new SignupRefused('NOT_RETRYING', 'Only a set-up that is being retried automatically can be held for review.');
        }
        $this->audit->record(AuditAction::SignupManualReview, $application, SignupStatus::FailedRetryable->value, $operator, $reason);

        return $application;
    }

    /** Runs the set-up again now, as this operator (a failed set-up, retried or held). */
    public function retry(SignupApplication $application, ?User $operator, string $reason): ?Tenant
    {
        [$operator, $reason] = $this->guard($operator, $reason, 'retry a signup set-up');
        $application = $application->fresh();
        if (! in_array($application->status, [SignupStatus::FailedRetryable, SignupStatus::FailedManualReview], true)) {
            throw new SignupRefused('NOT_FAILED', 'Only a failed set-up can be retried.');
        }

        return $this->provisioning->provision($application, $operator, $reason);
    }

    public function cancel(SignupApplication $application, ?User $operator, string $reason): SignupApplication
    {
        [$operator, $reason] = $this->guard($operator, $reason, 'cancel a signup application');

        return $this->applications->cancel($application, $operator, $reason);
    }

    /** Sends the account owner a new activation link (the existing first-administrator flow: throttled, locked, audited). */
    public function resendActivation(SignupApplication $application, ?User $operator, string $reason): void
    {
        [$operator, $reason] = $this->guard($operator, $reason, 'resend an activation e-mail');
        $application = $application->fresh();
        $tenant = $application->provisionedTenant;
        if ($application->status !== SignupStatus::ActivationPending || $tenant === null) {
            throw new SignupRefused('NOT_AVAILABLE', 'Only an application waiting for activation can be sent a new activation e-mail.');
        }
        app(TenantFirstAdministrator::class)->send($tenant, $operator, $reason);
        $application->forceFill(['invitation_sent_at' => now(), 'invitation_attempted_at' => now()])->save();
        $this->audit->record(AuditAction::SignupActivationResent, $application, $application->status->value, $operator, $reason, tenantChain: true);
    }

    /** @return array{0: User, 1: string} */
    private function guard(?User $operator, string $reason, string $what): array
    {
        if ($operator === null) {
            throw new SignupRefused('OPERATOR_REQUIRED', "Only platform operators can {$what}.");
        }
        PlatformOperator::assert($operator, $what);

        return [$operator, Str::limit(PlatformOperator::reason($reason), 500, '')];
    }
}
