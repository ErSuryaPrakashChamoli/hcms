<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Billing\Enums\ConfigurationKey;
use App\Domain\Entitlements\Models\PlanEntitlement;
use App\Domain\Entitlements\Services\PlanCatalog;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Services\TenantSuspensions;
use App\Domain\Signup\Enums\SignupStatus;
use App\Domain\Signup\Models\SignupApplication;
use App\Domain\Signup\Notifications\SignupAccountExists;
use App\Domain\Signup\Notifications\SignupLink;
use App\Domain\Signup\Notifications\SignupUpdate;
use App\Domain\Signup\Services\SignupApplications;
use App\Domain\Signup\Services\SignupCatalogue;
use App\Domain\Signup\Services\SignupEligibility;
use App\Domain\Signup\Services\SignupMaintenance;
use App\Domain\Signup\Services\SignupReview;
use App\Domain\Signup\Support\SignupRefused;
use App\Domain\Signup\Support\SignupTransitions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/SignupTestHelpers.php';

/*
 | SaaS.10: the applicant's side. Switch, single-use expiring links, no enumeration, server-side eligibility and review.
 */

beforeEach(function () {
    Notification::fake();
    RateLimiter::clear('signup-start-ip:203.0.113.7');
});

it('is off by default: nothing is accepted and nothing is stored', function () {
    expect(config('peopleos.signup.enabled'))->toBeFalse();

    expect(fn () => signupStart())->toThrow(SignupRefused::class, 'Self-service registration is not open');
    expect(SignupApplication::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('refuses safely, with an actionable message, while offers, prices or terms are not configured', function () {
    config(['peopleos.signup.enabled' => true]);
    expect(fn () => signupStart())->toThrow(SignupRefused::class, 'not offered');

    $setup = signupCatalogue(['offers' => 'core:30:AE']);
    expect(fn () => signupStart())->toThrow(SignupRefused::class, 'not offered for self-service registration in your country');
    expect(SignupApplication::query()->count())->toBe(0);
});

it('stores only the hash of a single-use, expiring link, and audits without the applicant\'s address', function () {
    signupCatalogue();
    signupStart();

    $application = SignupApplication::query()->sole();
    $token = lastSignupToken();
    expect($application->status)->toBe(SignupStatus::EmailPending)
        ->and($application->token_hash)->toBe(hash('sha256', $token))
        ->and(SignupApplication::query()->where('token_hash', $token)->exists())->toBeFalse()
        ->and($application->token_expires_at->between(now()->addHours(23), now()->addHours(25)))->toBeTrue()
        ->and(json_encode($application->toArray()))->not->toContain($application->token_hash);

    $events = AuditEvent::query()->withoutTenancy()->whereNull('tenant_id')->where('module', 'signup')->get();
    expect($events->pluck('action')->map->value->all())->toContain('SIGNUP_STARTED', 'SIGNUP_EMAIL_SENT')
        ->and($events->map(fn ($e) => json_encode($e->metadata))->implode(' '))->not->toContain('priya')
        ->and($events->map(fn ($e) => json_encode($e->metadata))->implode(' '))->not->toContain($token);
});

it('answers the same for an address that already has an account, and tells only its mailbox (D-17)', function () {
    signupCatalogue();
    $tenant = provisionTenant('Existing Co');
    app(TenantContext::class)->runAs($tenant, fn () => User::factory()->forTenant($tenant)->create(['email' => 'priya@acme-signup.test']));

    signupStart(); // no exception: the page shows the same "check your inbox"

    expect(SignupApplication::query()->count())->toBe(0);
    Notification::assertSentOnDemand(SignupAccountExists::class);
    Notification::assertNotSentTo(new AnonymousNotifiable, SignupLink::class);
});

it('keeps one open application per address: a second start re-sends its link (after a minute), never a second application', function () {
    signupCatalogue();
    signupStart();
    $first = lastSignupToken();
    signupStart(['company' => 'Another name']); // within a minute: quietly nothing

    expect(SignupApplication::query()->count())->toBe(1)
        ->and(SignupApplication::query()->sole()->tokens_sent)->toBe(1);

    $this->travel(2)->minutes();
    signupStart();
    $application = SignupApplication::query()->sole();
    expect($application->tokens_sent)->toBe(2)->and($application->company_name)->toBe('Acme Signup Pvt Ltd')
        ->and(app(SignupApplications::class)->linkUsable($first))->toBeFalse(); // the new link replaced the old one
});

it('consumes a link once, refuses an expired, replayed or forged one with one message, and opens the details step', function () {
    signupCatalogue();
    signupStart();
    $token = lastSignupToken();

    $application = app(SignupApplications::class)->confirm($token);
    expect($application->status)->toBe(SignupStatus::DetailsPending)->and($application->email_verified_at)->not->toBeNull()
        ->and($application->token_hash)->toBeNull()
        ->and($application->expires_at->between(now()->addDays(13), now()->addDays(15)))->toBeTrue();

    expect(fn () => app(SignupApplications::class)->confirm($token))->toThrow(SignupRefused::class, SignupApplications::LINK_INVALID);
    expect(fn () => app(SignupApplications::class)->confirm(str_repeat('a', 64)))->toThrow(SignupRefused::class, SignupApplications::LINK_INVALID);
    expect(fn () => app(SignupApplications::class)->confirm('short'))->toThrow(SignupRefused::class, SignupApplications::LINK_INVALID);

    signupStart(['email' => 'late@acme-signup.test']);
    $late = lastSignupToken('late@acme-signup.test');
    $this->travel(25)->hours();
    expect(fn () => app(SignupApplications::class)->confirm($late))->toThrow(SignupRefused::class, SignupApplications::LINK_INVALID);
});

it('decides approval on the server: flagged policy and nothing flagged approves; review policy "all" reviews', function () {
    signupCatalogue(['review' => 'flagged']);
    $approved = signupSubmitted();
    expect($approved->status)->toBe(SignupStatus::Approved)->and($approved->review_required)->toBeFalse()
        ->and($approved->trial_days)->toBe(30)->and($approved->terms_version)->toBe('2026.1')->and($approved->authority_confirmed)->toBeTrue()
        ->and($approved->acknowledged_at)->not->toBeNull();
    Notification::assertSentOnDemand(SignupUpdate::class);

    signupPolicy(ConfigurationKey::SignupReview, 'all', platformAdmin(), platformAdmin());
    $reviewed = signupSubmitted([], 'second@other-domain.test');
    expect($reviewed->status)->toBe(SignupStatus::ReviewPending)->and($reviewed->reviewReasons())->toBe([SignupEligibility::REVIEW_POLICY]);
});

it('reviews by default when no review policy was ever approved (shipped default: every application)', function () {
    signupCatalogue(['review' => null]);

    expect(signupSubmitted()->reviewReasons())->toBe([SignupEligibility::REVIEW_POLICY]);
});

it('flags a known company domain, an offer without self-service trial and an earlier rejection for review', function () {
    signupCatalogue(['offers' => 'core:0:IN']);
    $tenant = provisionTenant('Acme Existing');
    app(TenantContext::class)->runAs($tenant, fn () => User::factory()->forTenant($tenant)->create(['email' => 'someone@acme-signup.test']));

    $application = signupSubmitted();
    expect($application->status)->toBe(SignupStatus::ReviewPending)
        ->and($application->reviewReasons())->toBe([SignupEligibility::NO_SELF_SERVICE_TRIAL, SignupEligibility::DOMAIN_IN_USE]);

    app(SignupReview::class)->reject($application, platformAdmin(), 'Duplicate of an existing customer');
    $this->travel(2)->minutes();
    expect(signupSubmitted([], 'priya@acme-signup.test')->reviewReasons())->toContain(SignupEligibility::PREVIOUSLY_REJECTED);
});

it('refuses details that break the plan limit, a changed terms version, a time zone of another country and missing authority', function () {
    signupCatalogue();
    signupStart();
    $application = app(SignupApplications::class)->confirm(lastSignupToken());
    $valid = ['legal_name' => 'Acme Signup Private Limited', 'display_name' => 'Acme Signup', 'timezone' => 'Asia/Kolkata', 'intended_employees' => 120,
        'terms_version' => '2026.1', 'privacy_version' => '2026.1', 'authority' => true];
    $submit = fn (array $changes) => app(SignupApplications::class)->submit($application, $changes + $valid, '203.0.113.7');

    expect(fn () => $submit(['intended_employees' => 501]))->toThrow(SignupRefused::class, 'covers up to 500 employees');
    expect(fn () => $submit(['terms_version' => '2025.9']))->toThrow(SignupRefused::class, 'changed while you were registering');
    expect(fn () => $submit(['timezone' => 'Europe/London']))->toThrow(ValidationException::class);
    expect(fn () => $submit(['authority' => false]))->toThrow(ValidationException::class);
    expect($application->fresh()->status)->toBe(SignupStatus::DetailsPending);

    expect($submit([])->status)->toBe(SignupStatus::Approved);
    expect(fn () => $submit([]))->toThrow(SignupRefused::class, 'already been submitted');
});

it('limits starts per network and per address without revealing the address limit', function () {
    signupCatalogue();
    config(['peopleos.signup.limits.start_per_ip' => 2, 'peopleos.signup.limits.start_per_address' => 1]);

    signupStart(['email' => 'one@acme-signup.test'], '198.51.100.9');
    signupStart(['email' => 'one@acme-signup.test'], '198.51.100.10'); // address limit: silent, no second e-mail
    signupStart(['email' => 'two@acme-signup.test'], '198.51.100.9');
    expect(fn () => signupStart(['email' => 'three@acme-signup.test'], '198.51.100.9'))->toThrow(SignupRefused::class, 'Too many registrations from your network');

    expect(SignupApplication::query()->count())->toBe(2)
        ->and(SignupApplication::query()->where('email', 'one@acme-signup.test')->sole()->tokens_sent)->toBe(1);
});

it('ignores a filled honeypot: same answer, nothing stored or sent', function () {
    signupCatalogue();
    signupStart(['website' => 'https://spam.test']);

    expect(SignupApplication::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('lets the applicant withdraw before set-up and never resurrects a withdrawn application', function () {
    signupCatalogue();
    signupStart();
    $application = app(SignupApplications::class)->confirm(lastSignupToken());
    app(SignupApplications::class)->cancel($application, null, 'Withdrawn by the applicant');

    expect($application->fresh()->status)->toBe(SignupStatus::Cancelled);
    expect(fn () => app(SignupApplications::class)->cancel($application, null, 'Again'))->toThrow(SignupRefused::class, 'can no longer be cancelled');
    expect(fn () => app(SignupApplications::class)->submit($application, [], '203.0.113.7'))->toThrow(SignupRefused::class, 'already been submitted');

    $this->travel(2)->minutes();
    signupStart(); // a new attempt is a new application
    expect(SignupApplication::query()->count())->toBe(2)
        ->and(SignupApplication::query()->latest('id')->first()->status)->toBe(SignupStatus::EmailPending)
        ->and($application->fresh()->status)->toBe(SignupStatus::Cancelled);
});

it('only moves along the state machine (no invalid transition, terminal states stay terminal)', function () {
    foreach (SignupStatus::cases() as $from) {
        foreach (SignupStatus::cases() as $to) {
            expect($from->canBecome($to))->toBe(in_array($to->value, SignupStatus::TRANSITIONS[$from->value], true));
        }
    }
    foreach ([SignupStatus::Active, SignupStatus::Rejected, SignupStatus::Expired, SignupStatus::Cancelled] as $terminal) {
        expect($terminal->isTerminal())->toBeTrue();
    }
    expect(SignupStatus::ActivationPending->canBecome(SignupStatus::Cancelled))->toBeFalse()
        ->and(SignupStatus::Provisioning->canBecome(SignupStatus::Cancelled))->toBeFalse()
        ->and(SignupStatus::ReviewPending->canBecome(SignupStatus::Provisioning))->toBeFalse();

    $application = SignupApplication::query()->forceCreate(['reference' => (string) Str::ulid(), 'status' => 'rejected', 'company_name' => 'X', 'country_code' => 'IN', 'plan_code' => 'core']);
    expect(fn () => app(SignupTransitions::class)->move($application, SignupStatus::Approved))->toThrow(LogicException::class);
    expect(fn () => $application->delete())->toThrow(LogicException::class, 'never deleted');
});

it('fails safe on a retired plan version, a missing price and a plan without entitlements, with a stated code', function () {
    $setup = signupCatalogue();
    $codeOf = function (callable $call) {
        try {
            $call();
        } catch (SignupRefused $e) {
            return $e->reason;
        }

        return null;
    };
    $resolve = fn () => app(SignupCatalogue::class)->resolve('core', 'IN');

    // A plan offered in signup but without any price in the country's market.
    $other = publishedPlan($setup['operator'], 'growth', ['leave' => true, 'active_employees.max' => 2000], now()->toDateString());
    signupPolicy(ConfigurationKey::SignupOffers, 'core:30:IN, growth:30:IN', $setup['operator'], $setup['checker']);
    expect($codeOf(fn () => app(SignupCatalogue::class)->resolve('growth', 'IN')))->toBe('PRICE_NOT_CONFIGURED');

    // A country with no billing market.
    signupPolicy(ConfigurationKey::SignupOffers, 'core:30:IN|AE', $setup['operator'], $setup['checker']);
    expect($codeOf(fn () => app(SignupCatalogue::class)->resolve('core', 'AE')))->toBe('MARKET_NOT_CONFIGURED');

    // A plan whose definition carries no entitlement at all.
    PlanEntitlement::query()->where('plan_version_id', $setup['version']->id)->delete();
    expect($codeOf($resolve))->toBe('ENTITLEMENTS_NOT_CONFIGURED');
});

it('refuses a plan version that is no longer on sale (retired), before and after the applicant submitted', function () {
    $setup = signupCatalogue();
    $application = signupSubmitted();
    app(PlanCatalog::class)->retire($setup['version'], 'Withdrawn from sale (fictional)', $setup['operator']);

    expect(fn () => app(SignupCatalogue::class)->resolve('core', 'IN'))->toThrow(SignupRefused::class, 'not available at the moment');
    app(SignupMaintenance::class)->run();
    expect($application->fresh()->status)->toBe(SignupStatus::FailedManualReview)->and($application->fresh()->failure_code)->toBe('PLAN_VERSION_NOT_ON_SALE')
        ->and(Tenant::query()->count())->toBe(0);
});

it('never lets the applicant choose a tenant: identifiers in the request are ignored and the address is derived', function () {
    signupCatalogue();
    $existing = provisionTenant('Victim Co');
    signupStart(['tenant_id' => $existing->id, 'slug' => $existing->slug, 'provisioned_tenant_id' => $existing->id]);
    $application = app(SignupApplications::class)->confirm(lastSignupToken());
    $application = app(SignupApplications::class)->submit($application, ['legal_name' => 'Acme Signup Private Limited', 'display_name' => 'Acme Signup',
        'timezone' => 'Asia/Kolkata', 'intended_employees' => 120, 'terms_version' => '2026.1', 'privacy_version' => '2026.1', 'authority' => true,
        'tenant_id' => $existing->id, 'provisioned_tenant_id' => $existing->id, 'status' => 'active', 'review_required' => false], '203.0.113.7');

    expect($application->provisioned_tenant_id)->toBeNull()->and($application->status)->toBe(SignupStatus::Approved);
    app(SignupMaintenance::class)->run();
    $tenant = Tenant::query()->findOrFail($application->fresh()->provisioned_tenant_id);
    expect($tenant->id)->not->toBe($existing->id)->and(User::query()->where('tenant_id', $existing->id)->count())->toBe(0);
});

it('refuses a new activation e-mail while the new tenant is suspended, and throttles requests', function () {
    signupCatalogue();
    $application = signupSubmitted();
    app(SignupMaintenance::class)->run();
    $application->refresh();

    expect(fn () => app(SignupApplications::class)->resendActivation($application))->toThrow(SignupRefused::class, 'sent a few minutes ago');
    $this->travel(10)->minutes();
    app(SignupApplications::class)->resendActivation($application);
    $this->travel(10)->minutes();

    app(TenantSuspensions::class)->suspend($application->provisionedTenant, 'Fictional suspension', platformAdmin());
    expect(fn () => app(SignupApplications::class)->resendActivation($application->fresh()))->toThrow(SignupRefused::class, 'cannot be sent');
});
