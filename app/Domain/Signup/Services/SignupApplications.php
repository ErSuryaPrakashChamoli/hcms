<?php

namespace App\Domain\Signup\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserInvitation;
use App\Domain\Platform\Services\TenantFirstAdministrator;
use App\Domain\Platform\Support\PlatformOperator;
use App\Domain\Signup\Enums\SignupStatus;
use App\Domain\Signup\Models\SignupApplication;
use App\Domain\Signup\Notifications\SignupAccountExists;
use App\Domain\Signup\Notifications\SignupLink;
use App\Domain\Signup\Notifications\SignupUpdate;
use App\Domain\Signup\Support\SignupAudit;
use App\Domain\Signup\Support\SignupMailer;
use App\Domain\Signup\Support\SignupRefused;
use App\Domain\Signup\Support\SignupTransitions;
use App\Support\Tenancy\TenantContext;
use DateTimeZone;
use Filament\Facades\Filament;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * SaaS.10: the applicant's side of self-service signup: start, confirm the address, submit the company details and
 * acknowledgements, cancel, and ask for a new activation e-mail. Nobody is signed in by any of it; the applicant holds
 * the application through a single-use e-mail link and then their session.
 *
 * - start() answers the same, in effect, for every address: an address that already has an account gets an e-mail
 *   saying so; one with an open application gets a fresh link to it; a new one gets a new application. Only refusals
 *   that do not depend on the address (switch off, plan or country not offered, terms not configured, a malformed
 *   field, too many attempts from one network) are visible on the page.
 * - Links: 64 random characters, SHA-256 stored, single use, expiring (peopleos.signup.link_hours); a new link replaces
 *   the previous one. Confirming is a POST from the page, never the GET of the link (mail scanners open links).
 * - Every state change goes through SignupTransitions and is audited (SignupAudit); applicant steps anonymously.
 */
final class SignupApplications
{
    public const STARTED = 'Check your inbox. If this address can be used to register, we have sent it a link to continue. The link works once.';

    public const LINK_INVALID = 'This link is invalid, has already been used or has expired. Start again with the same e-mail address to receive a new one.';

    /** Seconds before an open application's link may be e-mailed again (a quiet no-op within it). */
    private const RESEND_SECONDS = 60;

    public function __construct(
        private readonly SignupCatalogue $catalogue,
        private readonly SignupEligibility $eligibility,
        private readonly SignupTransitions $transitions,
        private readonly SignupAudit $audit,
        private readonly SignupMailer $mailer,
    ) {}

    /**
     * Step 1. Throws a ValidationException for a malformed field and SignupRefused for an address-independent refusal;
     * otherwise returns quietly whatever happened (the caller shows STARTED).
     *
     * @param  array{email?: mixed, name?: mixed, company?: mixed, country?: mixed, plan?: mixed, website?: mixed}  $input
     */
    public function start(array $input, string $ip): void
    {
        $this->catalogue->assertEnabled();
        $data = Validator::make($input, [
            'email' => ['required', 'string', 'max:254', 'email:rfc,strict'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'company' => ['required', 'string', 'min:2', 'max:200'],
            'country' => ['required', 'string', 'size:2', 'alpha'],
            'plan' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
        ])->validate();
        $email = mb_strtolower(trim((string) $data['email']));
        $country = strtoupper((string) $data['country']);
        $this->catalogue->resolve((string) $data['plan'], $country);
        $this->catalogue->documents();
        if (filled($input['website'] ?? null)) {
            return; // honeypot: a person never fills it; a bot gets the same answer and nothing is sent
        }
        if (! $this->hit('signup-start-ip:'.$ip, (int) config('peopleos.signup.limits.start_per_ip', 10), 3600)) {
            throw new SignupRefused('RATE_LIMITED', 'Too many registrations from your network. Try again later.');
        }
        $hash = SignupApplication::hashEmail($email);
        if (! $this->hit('signup-start-address:'.$hash, (int) config('peopleos.signup.limits.start_per_address', 3), 3600)) {
            return; // per address: silent, so the limit reveals nothing about the address
        }

        if (SignupEligibility::accountExists($email)) {
            $this->mailer->send($email, new SignupAccountExists(Filament::getPanel('admin')->getLoginUrl(), Filament::getPanel('admin')->getRequestPasswordResetUrl()), 'account_exists');

            return;
        }
        $open = SignupApplication::query()->where('open_email_hash', $hash)->first();
        if ($open !== null) {
            $this->reissue($open);

            return;
        }

        $token = Str::random(64);
        try {
            $application = SignupApplication::query()->create([
                'reference' => (string) Str::ulid(),
                'status' => SignupStatus::EmailPending,
                'email' => $email,
                'email_hash' => $hash,
                'contact_name' => trim((string) $data['name']),
                'company_name' => trim((string) $data['company']),
                'country_code' => $country,
                'plan_code' => strtolower((string) $data['plan']),
                'token_hash' => hash('sha256', $token),
                'token_expires_at' => now()->addHours((int) config('peopleos.signup.link_hours', 24)),
                'token_sent_at' => now(),
                'tokens_sent' => 1,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A second start for the same address at the same moment: one open application; this one re-sends its link.
            if (($open = SignupApplication::query()->where('open_email_hash', $hash)->first()) !== null) {
                $this->reissue($open);
            }

            return;
        }
        $this->audit->record(AuditAction::SignupStarted, $application, null, null);
        $this->mailer->send($email, new SignupLink($this->linkUrl($token), $application->token_expires_at, false), 'confirmation_link', $application);
    }

    /**
     * Opens an application from its e-mail link (a POST from the confirmation page): the link is consumed, and an
     * application waiting for its address becomes `details_pending`. Every failure is LINK_INVALID.
     */
    public function confirm(#[SensitiveParameter] string $token): SignupApplication
    {
        $this->catalogue->assertEnabled();
        if (Str::length($token) !== 64) {
            throw new SignupRefused('LINK_INVALID', self::LINK_INVALID);
        }

        return DB::transaction(function () use ($token): SignupApplication {
            $application = SignupApplication::query()->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            if ($application === null || ! $application->status->isOpen() || $application->token_expires_at === null || $application->token_expires_at->lte(now())) {
                throw new SignupRefused('LINK_INVALID', self::LINK_INVALID);
            }
            if ($application->status === SignupStatus::EmailPending) {
                $moved = $this->transitions->move($application, SignupStatus::DetailsPending, ['token_hash' => null, 'token_expires_at' => null, 'email_verified_at' => now(),
                    'expires_at' => now()->addDays((int) config('peopleos.signup.completion_days', 14))]);
                $moved && $this->audit->record(AuditAction::SignupEmailVerified, $application, SignupStatus::EmailPending->value, null);
            } else {
                $application->forceFill(['token_hash' => null, 'token_expires_at' => null])->save();
            }

            return $application->fresh();
        });
    }

    /** Whether a link would open an application (read-only; the page asks before offering the confirm button). */
    public function linkUsable(#[SensitiveParameter] string $token): bool
    {
        if (! $this->catalogue->enabled() || Str::length($token) !== 64) {
            return false;
        }
        $application = SignupApplication::query()->where('token_hash', hash('sha256', $token))->first();

        return $application !== null && $application->status->isOpen() && $application->token_expires_at?->gt(now());
    }

    /** The application a confirmed link bound to this session, by reference (never anything the browser sends). */
    public function forReference(?string $reference): ?SignupApplication
    {
        return $reference === null || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $reference) !== 1
            ? null
            : SignupApplication::query()->where('reference', $reference)->first();
    }

    /**
     * Step 2: the company details and acknowledgements. Decides, on the server, whether an operator reviews it first.
     *
     * @param  array{legal_name?: mixed, display_name?: mixed, timezone?: mixed, intended_employees?: mixed, terms_version?: mixed, privacy_version?: mixed, authority?: mixed}  $input
     */
    public function submit(SignupApplication $application, array $input, string $ip): SignupApplication
    {
        $this->catalogue->assertEnabled();
        $application = $application->fresh();
        if ($application->status !== SignupStatus::DetailsPending) {
            throw new SignupRefused('NOT_EDITABLE', 'This application has already been submitted.');
        }
        if ($application->expires_at !== null && $application->expires_at->lte(now())) {
            throw new SignupRefused('EXPIRED', 'This application has expired. Start again to register.');
        }
        $data = Validator::make($input, [
            'legal_name' => ['required', 'string', 'min:2', 'max:200'],
            'display_name' => ['required', 'string', 'min:2', 'max:120'],
            'timezone' => ['required', 'string', 'in:'.implode(',', self::timezones($application->country_code))],
            'intended_employees' => ['required', 'integer', 'min:1', 'max:'.(int) config('peopleos.signup.max_intended_employees', 1_000_000)],
            'terms_version' => ['required', 'string', 'max:32'],
            'privacy_version' => ['required', 'string', 'max:32'],
            'authority' => ['accepted'],
        ], ['authority.accepted' => 'Confirm that you may register this organisation.'])->validate();

        $documents = $this->catalogue->documents();
        if ($data['terms_version'] !== $documents['terms']['version'] || $data['privacy_version'] !== $documents['privacy']['version']) {
            throw new SignupRefused('TERMS_CHANGED', 'The Terms of Service or the Privacy Notice changed while you were registering. Read the current versions and accept them again.');
        }
        $offer = $this->catalogue->resolve($application->plan_code, $application->country_code);
        $this->eligibility->assertWithinLimit($offer, (int) $data['intended_employees']);
        $reasons = $this->eligibility->reviewReasons($application, $offer);
        $to = $reasons === [] ? SignupStatus::Approved : SignupStatus::ReviewPending;

        $moved = $this->transitions->move($application, $to, [
            'legal_name' => trim((string) $data['legal_name']), 'display_name' => trim((string) $data['display_name']), 'timezone' => $data['timezone'],
            'intended_employees' => (int) $data['intended_employees'], 'plan_version_id' => $offer['version']->id, 'billing_market_id' => $offer['market']->id,
            'trial_days' => (int) $offer['trial_days'], 'terms_version' => $documents['terms']['version'], 'privacy_version' => $documents['privacy']['version'],
            'authority_confirmed' => true, 'acknowledged_at' => now(), 'acknowledged_ip' => Str::limit($ip, 45, ''), 'submitted_at' => now(),
            'review_required' => $reasons !== [], 'review_reasons' => $reasons, 'expires_at' => null,
        ], [SignupStatus::DetailsPending]);
        if (! $moved) {
            throw new SignupRefused('NOT_EDITABLE', 'This application has already been submitted.');
        }
        $this->audit->record(AuditAction::SignupSubmitted, $application, SignupStatus::DetailsPending->value, null, null,
            ['review_reasons' => $reasons, 'plan_version_id' => $offer['version']->id, 'trial_days' => (int) $offer['trial_days'], 'terms_version' => $documents['terms']['version'],
                'privacy_version' => $documents['privacy']['version']]);
        if ($to === SignupStatus::Approved) {
            $this->audit->record(AuditAction::SignupApproved, $application, SignupStatus::DetailsPending->value, null, 'No review required (signup.review = flagged; nothing flagged)');
        }
        $this->mailer->send((string) $application->email, new SignupUpdate($to === SignupStatus::Approved ? 'setting_up' : 'review', $application->reference,
            (string) $application->display_name), $to === SignupStatus::Approved ? 'setting_up' : 'in_review', $application);

        return $application;
    }

    /** The applicant withdraws (no operator), or an operator cancels (named, with a reason). */
    public function cancel(SignupApplication $application, ?User $operator, string $reason): SignupApplication
    {
        if ($operator !== null) {
            PlatformOperator::assert($operator, 'cancel a signup application');
            $reason = PlatformOperator::reason($reason);
        }
        $application = $application->fresh();
        $from = $application->status;
        if (! $from->cancellable() || ! $this->transitions->move($application, SignupStatus::Cancelled, ['cancelled_at' => now(), 'cancelled_by' => $operator?->getKey(),
            'cancel_reason' => Str::limit(trim($reason), 500, ''), 'token_hash' => null, 'token_expires_at' => null])) {
            throw new SignupRefused('NOT_CANCELLABLE', 'This application can no longer be cancelled.');
        }
        $this->audit->record(AuditAction::SignupCancelled, $application, $from->value, $operator, $reason);

        return $application;
    }

    /** The applicant asks for a new activation e-mail (their first one expired or went astray). Throttled. */
    public function resendActivation(SignupApplication $application): void
    {
        $this->catalogue->assertEnabled();
        $application = $application->fresh();
        $tenant = $application->provisionedTenant;
        $user = $application->provisioned_user_id ? User::query()->find($application->provisioned_user_id) : null;
        if ($application->status !== SignupStatus::ActivationPending || $tenant === null || $user === null || $user->status !== UserStatus::Invited || ! $tenant->isAccessible()) {
            throw new SignupRefused('NOT_AVAILABLE', 'A new activation e-mail cannot be sent for this application.');
        }
        $seconds = (int) config('peopleos.signup.limits.activation_resend_seconds', 300);
        $recent = app(TenantContext::class)->runAs($tenant, fn () => UserInvitation::query()->where('user_id', $user->id)->where('created_at', '>', now()->subSeconds($seconds))->exists());
        if ($recent) {
            throw new SignupRefused('THROTTLED', 'An activation e-mail was sent a few minutes ago. Check your inbox and spam folder, or try again later.');
        }
        $sent = app(TenantFirstAdministrator::class)->inviteAfterProvisioning($tenant, $user, null, 'Activation e-mail requested by the applicant');
        $application->forceFill(['invitation_attempted_at' => now()] + ($sent ? ['invitation_sent_at' => now()] : []))->save();
        $this->audit->record(AuditAction::SignupActivationResent, $application, $application->status->value, null, null, ['accepted_by_mail_transport' => $sent], tenantChain: true);
    }

    /** @return list<string> the IANA time zones of a country (from the time-zone database, never a list kept here) */
    public static function timezones(string $country): array
    {
        return DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, strtoupper($country)) ?: ['UTC'];
    }

    /** A new link for an open application (throttled quietly); continuing unless it still waits for its address. */
    private function reissue(SignupApplication $open): void
    {
        if ($open->token_sent_at !== null && $open->token_sent_at->gt(now()->subSeconds(self::RESEND_SECONDS))) {
            return;
        }
        $token = Str::random(64);
        $changed = SignupApplication::query()->whereKey($open->id)->where('status', $open->status->value)->update([
            'token_hash' => hash('sha256', $token), 'token_expires_at' => now()->addHours((int) config('peopleos.signup.link_hours', 24)),
            'token_sent_at' => now(), 'tokens_sent' => DB::raw('tokens_sent + 1'), 'updated_at' => now(),
        ]);
        if ($changed !== 1) {
            return;
        }
        $open->refresh();
        $this->mailer->send((string) $open->email, new SignupLink($this->linkUrl($token), $open->token_expires_at, $open->status !== SignupStatus::EmailPending),
            $open->status === SignupStatus::EmailPending ? 'confirmation_link' : 'continue_link', $open);
    }

    private function linkUrl(#[SensitiveParameter] string $token): string
    {
        return route('filament.admin.signup.confirm', ['token' => $token]);
    }

    private function hit(string $key, int $max, int $decay): bool
    {
        if (RateLimiter::tooManyAttempts($key, $max)) {
            return false;
        }
        RateLimiter::hit($key, $decay);

        return true;
    }
}
