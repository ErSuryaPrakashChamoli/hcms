<?php

namespace App\Domain\Signup\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Signup\Enums\SignupStatus;
use App\Domain\Signup\Models\SignupApplication;
use App\Domain\Signup\Support\SignupRefused;

/**
 * SaaS.10: whether an application may proceed, and whether a person must review it first. Decided on the server from
 * the application and the approved configuration, never from anything the request says about itself. Re-checked at
 * provisioning, so an offer withdrawn, a plan version retired, a price removed or an address taken in the meantime
 * stops the set-up instead of being bypassed.
 */
final class SignupEligibility
{
    /** Every application is reviewed (`signup.review` = all, the default; D-9 sales-led first). */
    public const REVIEW_POLICY = 'REVIEW_POLICY';

    /** The offer has no self-service trial (0 days): Markedge sets the commercial terms. */
    public const NO_SELF_SERVICE_TRIAL = 'NO_SELF_SERVICE_TRIAL';

    /** Another organisation already has accounts at the applicant's e-mail domain (possible duplicate company). */
    public const DOMAIN_IN_USE = 'DOMAIN_IN_USE';

    /** An earlier application from this address was rejected. */
    public const PREVIOUSLY_REJECTED = 'PREVIOUSLY_REJECTED';

    public const OVER_PLAN_LIMIT = 'OVER_PLAN_LIMIT';

    public const EMAIL_IN_USE = 'EMAIL_IN_USE';

    public const PLAN_VERSION_CHANGED = 'PLAN_VERSION_CHANGED';

    public const MARKET_CHANGED = 'MARKET_CHANGED';

    public const REVIEW_LABELS = [
        self::REVIEW_POLICY => 'Every application is reviewed (signup.review = all)',
        self::NO_SELF_SERVICE_TRIAL => 'The offer has no self-service trial: Markedge agrees the commercial terms',
        self::DOMAIN_IN_USE => 'Another organisation already has accounts at this e-mail domain (possible duplicate)',
        self::PREVIOUSLY_REJECTED => 'An earlier application from this address was rejected',
    ];

    public function __construct(private readonly SignupCatalogue $catalogue) {}

    /** @param  array<string, mixed>  $offer */
    public function assertWithinLimit(array $offer, int $intendedEmployees): void
    {
        $limit = $offer['employee_limit'];
        if ($limit !== null && $intendedEmployees > $limit) {
            throw new SignupRefused(self::OVER_PLAN_LIMIT, 'The '.$offer['name'].' plan covers up to '.number_format($limit).' employees. Choose a larger plan, or contact Markedge.');
        }
    }

    /**
     * @param  array<string, mixed>  $offer
     * @return list<string> why an operator must review the application (empty: it may be provisioned without review)
     */
    public function reviewReasons(SignupApplication $application, array $offer): array
    {
        $reasons = [];
        if ($this->catalogue->reviewPolicy() === 'all') {
            $reasons[] = self::REVIEW_POLICY;
        }
        if ((int) $offer['trial_days'] === 0) {
            $reasons[] = self::NO_SELF_SERVICE_TRIAL;
        }
        $domain = $application->domain();
        if ($domain !== null && User::query()->whereNotNull('tenant_id')->where('email', 'like', '%@'.addcslashes($domain, '%_\\'))->exists()) {
            $reasons[] = self::DOMAIN_IN_USE;
        }
        if ($application->email_hash !== null && SignupApplication::query()->where('email_hash', $application->email_hash)->whereKeyNot($application->getKey())
            ->where('status', SignupStatus::Rejected->value)->exists()) {
            $reasons[] = self::PREVIOUSLY_REJECTED;
        }

        return $reasons;
    }

    /**
     * Re-checked when an operator approves and when provisioning starts: the offer the applicant accepted is still the
     * one on sale, and the address still has no account (D-17: one login per address).
     *
     * @return array<string, mixed> the resolved offer
     */
    public function assertProvisionable(SignupApplication $application): array
    {
        $offer = $this->catalogue->resolve($application->plan_code, $application->country_code);
        if ((int) $offer['version']->id !== (int) $application->plan_version_id) {
            throw new SignupRefused(self::PLAN_VERSION_CHANGED, 'The plan version the applicant accepted is no longer on sale.');
        }
        if ((int) $offer['market']->id !== (int) $application->billing_market_id) {
            throw new SignupRefused(self::MARKET_CHANGED, 'The billing market for the applicant\'s country has changed since they applied.');
        }
        $this->assertWithinLimit($offer, (int) $application->intended_employees);
        if ($application->email === null || self::accountExists($application->email)) {
            throw new SignupRefused(self::EMAIL_IN_USE, 'The applicant\'s e-mail address now belongs to a PeopleOS account (one login per address).');
        }

        return $offer;
    }

    /** Whether the address already signs in to PeopleOS (any tenant, or an operator). */
    public static function accountExists(string $email): bool
    {
        $email = trim($email);

        return User::query()->where('email', mb_strtolower($email))->orWhere('email', $email)->exists();
    }
}
