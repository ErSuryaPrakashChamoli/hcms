<?php

namespace App\Domain\Signup\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Models\BillingMarket;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Actions\ProvisionTenantAction;
use App\Domain\Platform\Enums\TenantStatus;
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
use App\Domain\Signup\Support\StaleClaim;
use App\Domain\Subscriptions\Services\CommercialSubscriptions;
use App\Support\Money\Currency;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use NumberFormatter;
use RuntimeException;
use Throwable;

/**
 * SaaS.10: turns an approved application into a tenant, idempotently and recoverably.
 *
 * 1. Claim: a conditional update to `provisioning` with a new fencing token (`claim_token`) and `claimed_at` (lease).
 *    Losing the race means another run owns it: nothing happens.
 * 2. Core, one transaction, the application row locked: the claim is still ours and no tenant is linked; eligibility
 *    is re-checked (offer, plan version, market, price, limit, the address still free); then ProvisionTenantAction's
 *    core (tenant, system roles, settings, defaults, owner as `invited` Account Owner), the trial of the accepted offer
 *    (never a paid subscription), and the application moves to `activation_pending` with its links. Anything failing
 *    rolls all of it back: no tenant, no user, no subscription.
 * 3. After the commit only: the owner's invitation (the existing one-time link). A mail failure is recorded; the tick
 *    sends it again. Until the owner chooses a password nobody can sign in, so a tenant never looks active and unusable.
 * 4. Failure: `failed_retryable` with backoff, or `failed_manual_review` (a stated eligibility refusal, or attempts
 *    exhausted). Only a code and a safe summary are kept: never an exception message, SQL or personal data.
 *
 * No operator: the console only (the signup tick; PlatformOperator). An operator (approve, retry) is named in the audit.
 */
final class SignupProvisioning
{
    public const LOCK = 'signup:provisioning';

    private const LOCK_SECONDS = 600;

    public function __construct(
        private readonly SignupEligibility $eligibility,
        private readonly SignupTransitions $transitions,
        private readonly SignupAudit $audit,
        private readonly SignupMailer $mailer,
    ) {}

    /** Provisions the application if this run can claim it. Returns the tenant, or null (not claimable, or failed and recorded). */
    public function provision(SignupApplication $application, ?User $operator = null, ?string $reason = null): ?Tenant
    {
        PlatformOperator::assert($operator, 'provision a signup application');
        // One provisioning at a time, across servers (the application cache's atomic lock). Two new tenants provisioned at
        // once deadlocked in the audit chain's head index (gap locks between new chains, MySQL performance probe). Busy:
        // nothing is claimed; the tick (or the operator again) provisions it shortly.
        $serial = Cache::lock(self::LOCK, self::LOCK_SECONDS);
        try {
            $serial->block((int) config('peopleos.signup.provisioning.wait_seconds', 30));
        } catch (LockTimeoutException) {
            return null;
        }
        try {
            $application = $application->fresh();
            $token = $this->claim($application, $operator, $reason);
            if ($token === null) {
                return null;
            }
            try {
                // A deadlock rolls the whole core back; it is safe to run it again under the same claim (the token is still ours).
                [$tenant, $owner] = DB::transaction(fn () => $this->core((int) $application->id, $token, $operator), 3);
            } catch (StaleClaim) {
                return null;
            } catch (Throwable $e) {
                $this->fail($application, $token, $e, $operator);

                return null;
            }
        } finally {
            $serial->release();
        }

        $application = $application->fresh();
        $this->invite($application, $tenant, $owner, $operator);

        return $tenant;
    }

    /** The owner's invitation after provisioning, and the tick's retries. Records whether the mail transport accepted it. */
    public function invite(SignupApplication $application, Tenant $tenant, User $owner, ?User $operator = null): bool
    {
        $sent = app(TenantFirstAdministrator::class)->inviteAfterProvisioning($tenant, $owner, $operator, "Account owner of a new organisation (signup {$application->reference})");
        SignupApplication::query()->whereKey($application->id)->update(['invitation_attempted_at' => now(), 'updated_at' => now()]
            + ($sent ? ['invitation_sent_at' => now()] : ['invitation_failures' => DB::raw('invitation_failures + 1')]));
        $this->audit->record($sent ? AuditAction::SignupEmailSent : AuditAction::SignupEmailFailed, $application->fresh(), $application->status->value, $operator, null,
            ['kind' => 'activation', 'meaning' => $sent ? 'accepted by the mail transport (not proof of delivery)' : 'refused by the mail transport'], tenantChain: true);

        return $sent;
    }

    private function claim(SignupApplication $application, ?User $operator, ?string $reason): ?string
    {
        $token = (string) Str::ulid();
        $from = $operator !== null
            ? [SignupStatus::Approved, SignupStatus::FailedRetryable, SignupStatus::FailedManualReview]
            : [SignupStatus::Approved, SignupStatus::FailedRetryable];
        if (! in_array($application->status, $from, true)) {
            return null;
        }
        if ($operator === null && $application->status === SignupStatus::FailedRetryable && $application->next_attempt_at?->gt(now())) {
            return null; // backoff not over
        }
        $from = $application->status;
        $moved = $this->transitions->move($application, SignupStatus::Provisioning, ['claim_token' => $token, 'claimed_at' => now(), 'attempts' => DB::raw('attempts + 1'),
            'next_attempt_at' => null, 'failure_code' => null, 'failure_summary' => null], [$from]);
        if (! $moved) {
            return null;
        }
        $this->audit->record(AuditAction::SignupProvisioningStarted, $application, $from->value, $operator, $reason, ['attempt' => $application->attempts]);

        return $token;
    }

    /** @return array{0: Tenant, 1: User} */
    private function core(int $id, string $token, ?User $operator): array
    {
        $application = SignupApplication::query()->lockForUpdate()->findOrFail($id);
        if ($application->status !== SignupStatus::Provisioning || $application->claim_token !== $token || $application->provisioned_tenant_id !== null) {
            throw new StaleClaim;
        }
        $offer = $this->eligibility->assertProvisionable($application);
        $trialDays = (int) $application->trial_days;
        $today = now()->toDateString();
        $trialEnd = $trialDays > 0 ? now()->addDays($trialDays - 1)->toDateString() : null;

        [$tenant, $owner] = app(ProvisionTenantAction::class)->provisionCore(
            [
                'name' => (string) $application->display_name,
                'slug' => $this->slug((string) $application->display_name),
                'status' => $trialDays > 0 ? TenantStatus::Trial : TenantStatus::Active,
                'country_code' => $application->country_code,
                'timezone' => (string) $application->timezone,
                'locale' => 'en',
                'currency' => $this->currency($application->country_code, $offer['market']),
                'trial_ends_at' => $trialEnd,
            ],
            ['name' => (string) $application->contact_name, 'email' => (string) $application->email],
            "Self-service signup {$application->reference}",
            $operator,
            'account-owner',
            ['signup_reference' => $application->reference, 'channel' => 'self_service_signup'],
        );
        if ($owner === null) {
            throw new RuntimeException('Provisioning returned no account owner.');
        }
        $subscription = $trialDays > 0
            ? app(CommercialSubscriptions::class)->startSignupTrial($tenant, $offer['version'], $today, (string) $trialEnd, $application->reference, $operator)
            : null;

        $moved = $this->transitions->move($application, SignupStatus::ActivationPending, ['provisioned_tenant_id' => $tenant->id, 'provisioned_user_id' => $owner->id,
            'subscription_id' => $subscription?->id, 'provisioned_at' => now(), 'claim_token' => null], [SignupStatus::Provisioning], $token);
        if (! $moved) {
            throw new StaleClaim;
        }
        $this->audit->record(AuditAction::SignupProvisioned, $application, SignupStatus::Provisioning->value, $operator, null,
            ['subject_tenant_id' => $tenant->id, 'subject_tenant' => $tenant->slug, 'trial_days' => $trialDays, 'trial_until' => $trialEnd, 'subscription_id' => $subscription?->id,
                'first_administrator_role' => 'account-owner'], tenantChain: true);

        return [$tenant, $owner];
    }

    private function fail(SignupApplication $application, string $token, Throwable $e, ?User $operator): void
    {
        $application = $application->fresh();
        $refused = $e instanceof SignupRefused;
        $max = (int) config('peopleos.signup.provisioning.max_attempts', 5);
        $manual = $refused || $application->attempts >= $max;
        $code = $refused ? $e->reason : 'PROVISIONING_ERROR';
        $summary = $refused
            ? Str::limit($e->getMessage(), 255, '')
            : 'Set-up failed ('.class_basename($e).'); nothing was kept.'.($manual ? ' Attempts are exhausted: an operator decides.' : ' It is retried automatically.');
        if (! $refused) {
            Log::error('Signup provisioning failed.', ['reference' => $application->reference, 'attempt' => $application->attempts, 'error' => class_basename($e),
                'at' => basename($e->getFile()).':'.$e->getLine()]);
        }
        $backoff = (array) config('peopleos.signup.provisioning.backoff_minutes', [1, 5, 15, 60]);
        $minutes = (int) ($backoff[min(max($application->attempts - 1, 0), count($backoff) - 1)] ?? 15);
        $to = $manual ? SignupStatus::FailedManualReview : SignupStatus::FailedRetryable;
        $moved = $this->transitions->move($application, $to, ['claim_token' => null, 'failure_code' => $code, 'failure_summary' => $summary, 'failed_at' => now(),
            'next_attempt_at' => $manual ? null : now()->addMinutes($minutes)], [SignupStatus::Provisioning], $token);
        if (! $moved) {
            return; // the lease expired and the tick moved it meanwhile
        }
        $this->audit->record(AuditAction::SignupProvisioningFailed, $application, SignupStatus::Provisioning->value, $operator, null, ['code' => $code, 'attempt' => $application->attempts]);
        if ($manual) {
            $this->audit->record(AuditAction::SignupManualReview, $application, SignupStatus::Provisioning->value, $operator, $summary, ['code' => $code]);
            $this->mailer->send((string) $application->email, new SignupUpdate('delayed', $application->reference, (string) $application->display_name), 'delayed', $application);
        }
    }

    /** A unique tenant address from the company name; never a reserved word, never chosen by the applicant. */
    private function slug(string $name): string
    {
        $base = Str::limit(Str::slug($name), 48, '');
        $base = trim($base, '-');
        if ($base === '' || in_array($base, (array) config('peopleos.signup.reserved_slugs', []), true)) {
            $base = trim(($base === '' ? 'organisation' : $base.'-org'), '-');
        }
        $candidate = $base;
        for ($n = 2; Tenant::query()->where('slug', $candidate)->exists(); $n++) {
            $candidate = $n <= 50 ? "{$base}-{$n}" : $base.'-'.Str::lower(Str::random(6));
        }

        return $candidate;
    }

    /** The country's currency from ICU's locale data (validated against the supported catalogue), else the market's. */
    private function currency(string $country, BillingMarket $market): string
    {
        $code = (new NumberFormatter('en_'.strtoupper($country), NumberFormatter::CURRENCY))->getTextAttribute(NumberFormatter::CURRENCY_CODE);

        return Currency::tryFrom((string) $code)?->value ?? $market->currency->value;
    }
}
