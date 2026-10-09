<?php

use App\Domain\Billing\Enums\ConfigurationKey;
use App\Domain\Billing\Models\BillingMarket;
use App\Domain\Billing\Services\CommercialConfiguration;
use App\Domain\Entitlements\Models\PlanVersion;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\PermissionRegistry;
use App\Domain\Signup\Models\SignupApplication;
use App\Domain\Signup\Notifications\SignupLink;
use App\Domain\Signup\Services\SignupApplications;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/../Entitlements/PlanTestHelpers.php';
require_once __DIR__.'/../Billing/BillingTestHelpers.php';

/*
 | SaaS.10 test fixtures. Everything here is fictional: a test plan, an IN market with a fictional price, and signup
 | policies approved by a second operator (the production path). Public registration is switched on for the test only.
 */

/**
 * @param  array{plan?: string, values?: array<string, mixed>, offers?: string, review?: string|null, countries?: list<string>, enabled?: bool}  $options
 * @return array{operator: User, checker: User, version: PlanVersion, market: BillingMarket}
 */
function signupCatalogue(array $options = []): array
{
    app(PermissionRegistry::class)->sync(); // the permission catalogue a provisioned tenant's roles are seeded from
    [$operator, $checker] = [platformAdmin(), platformAdmin()];
    $today = now()->toDateString();
    $version = publishedPlan($operator, $options['plan'] ?? 'core', $options['values'] ?? ['leave' => true, 'active_employees.max' => 500], $today);
    $market = billingMarket($operator, 'IN-TEST', 'INR', 'MARKEDGE-IN-TEST', 'en_IN', $options['countries'] ?? ['IN']);
    pepmPrice($version, $market, 'month', '120.00', $today, $operator, $checker);
    signupPolicy(ConfigurationKey::SignupOffers, $options['offers'] ?? 'core:30:IN', $operator, $checker);
    signupPolicy(ConfigurationKey::SignupTerms, '2026.1 https://example.test/terms', $operator, $checker);
    signupPolicy(ConfigurationKey::SignupPrivacyNotice, '2026.1 https://example.test/privacy', $operator, $checker);
    if (array_key_exists('review', $options) ? $options['review'] !== null : true) {
        signupPolicy(ConfigurationKey::SignupReview, $options['review'] ?? 'flagged', $operator, $checker);
    }
    config(['peopleos.signup.enabled' => $options['enabled'] ?? true]);

    return ['operator' => $operator, 'checker' => $checker, 'version' => $version, 'market' => $market];
}

function signupPolicy(ConfigurationKey $key, mixed $value, User $maker, User $checker): void
{
    approveAs(app(CommercialConfiguration::class)->propose($key, '', $value, now()->toDateString(), null, 'Signup policy (fictional)', $maker), $checker);
}

/** @param  array<string, mixed>  $overrides */
function signupStart(array $overrides = [], string $ip = '203.0.113.7'): void
{
    app(SignupApplications::class)->start($overrides + ['email' => 'priya@acme-signup.test', 'name' => 'Priya Owner', 'company' => 'Acme Signup Pvt Ltd',
        'country' => 'IN', 'plan' => 'core'], $ip);
}

/** The token in the last signup link e-mailed to $email (Notification::fake() must be on). */
function lastSignupToken(string $email = 'priya@acme-signup.test'): string
{
    $url = null;
    Notification::assertSentOnDemand(SignupLink::class, function (SignupLink $notification, array $channels, AnonymousNotifiable $notifiable) use ($email, &$url) {
        if (($notifiable->routes['mail'] ?? null) === $email) {
            $url = $notification->toMail($notifiable)->actionUrl;
        }

        return true;
    });
    expect($url)->not->toBeNull();

    return basename((string) parse_url((string) $url, PHP_URL_PATH));
}

/** @param  array<string, mixed>  $details */
function signupSubmitted(array $details = [], string $email = 'priya@acme-signup.test'): SignupApplication
{
    signupStart(['email' => $email]);
    $application = app(SignupApplications::class)->confirm(lastSignupToken($email));

    return app(SignupApplications::class)->submit($application, $details + ['legal_name' => 'Acme Signup Private Limited', 'display_name' => 'Acme Signup',
        'timezone' => 'Asia/Kolkata', 'intended_employees' => 120, 'terms_version' => '2026.1', 'privacy_version' => '2026.1', 'authority' => true], '203.0.113.7');
}
