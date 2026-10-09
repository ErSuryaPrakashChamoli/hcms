<?php

use App\Domain\Billing\Enums\ConfigurationKey;
use App\Domain\Identity\Models\User;
use App\Domain\Signup\Enums\SignupStatus;
use App\Domain\Signup\Models\SignupApplication;
use App\Domain\Signup\Services\SignupApplications;
use App\Filament\Pages\PlatformSignupsPage;
use App\Filament\Signup\SignupApplicationPage;
use App\Filament\Signup\SignupConfirm;
use App\Filament\Signup\SignupStart;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

require_once __DIR__.'/SignupTestHelpers.php';

/*
 | SaaS.10: the public signup pages and the operators' page.
 */

beforeEach(function () {
    Notification::fake();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    RateLimiter::clear('signup-start-ip:127.0.0.1');
});

it('shows only a notice on every signup page while registration is off, and accepts nothing', function () {
    foreach (['/admin/signup', '/admin/signup/application', '/admin/signup/confirm/'.str_repeat('a', 64)] as $url) {
        $this->get($url)->assertOk()->assertSee('Self-service registration is not open')->assertDontSee('Send me a confirmation link');
    }

    Livewire::test(SignupStart::class)->fillForm(['country' => 'IN', 'plan' => 'core', 'email' => 'x@y.test', 'name' => 'X Y', 'company' => 'XY Ltd'])
        ->call('start')->assertSet('sent', false);
    expect(SignupApplication::query()->count())->toBe(0);
    $this->get('/admin/login')->assertOk(); // existing sign-in unaffected
});

it('offers the approved plans for the country and answers the same for a new address and one with an account', function () {
    signupCatalogue();
    $this->get('/admin/signup')->assertOk()->assertSee('Core (test)')->assertSee('30-day trial')->assertSee('per employee per month');

    Livewire::test(SignupStart::class)->fillForm(['country' => 'IN', 'plan' => 'core', 'email' => 'new@acme-signup.test', 'name' => 'New Person', 'company' => 'Acme New'])
        ->call('start')->assertHasNoFormErrors()->assertSet('sent', true)->assertSee(SignupApplications::STARTED);

    $tenant = provisionTenant('Existing');
    app(TenantContext::class)->runAs($tenant, fn () => User::factory()->forTenant($tenant)->create(['email' => 'taken@acme-signup.test']));
    Livewire::test(SignupStart::class)->fillForm(['country' => 'IN', 'plan' => 'core', 'email' => 'taken@acme-signup.test', 'name' => 'Taken Person', 'company' => 'Acme Taken'])
        ->call('start')->assertHasNoFormErrors()->assertSet('sent', true)->assertSee(SignupApplications::STARTED);

    expect(SignupApplication::query()->count())->toBe(1);
});

it('does not consume a link by opening it; continuing binds the application to a new session', function () {
    signupCatalogue();
    signupStart();
    $token = lastSignupToken();

    $this->get('/admin/signup/confirm/'.$token)->assertOk()->assertSee('Continue to register');
    expect(app(SignupApplications::class)->linkUsable($token))->toBeTrue();

    $before = session()->getId();
    Livewire::test(SignupConfirm::class, ['token' => $token])->call('confirm')->assertRedirect(route('filament.admin.signup.application'));
    $application = SignupApplication::query()->sole();
    expect(session(SignupApplicationPage::SESSION_KEY))->toBe($application->reference)
        ->and(session()->getId())->not->toBe($before)
        ->and($application->status)->toBe(SignupStatus::DetailsPending)
        ->and(app(SignupApplications::class)->linkUsable($token))->toBeFalse();

    $this->get('/admin/signup/confirm/'.$token)->assertOk()->assertSee('invalid, has already been used or has expired');
});

it('collects the details and acknowledgements on the session-bound page, then shows where the application stands', function () {
    signupCatalogue();
    signupStart();
    $application = app(SignupApplications::class)->confirm(lastSignupToken());
    session([SignupApplicationPage::SESSION_KEY => $application->reference]);

    Livewire::test(SignupApplicationPage::class)
        ->assertSee('Terms of Service')->assertSee('version 2026.1')
        ->fillForm(['legal_name' => 'Acme Signup Private Limited', 'display_name' => 'Acme Signup', 'timezone' => 'Asia/Kolkata', 'intended_employees' => 120,
            'accept_terms' => true, 'accept_privacy' => true, 'authority' => true])
        ->call('submitDetails')->assertHasNoFormErrors();

    expect($application->fresh()->status)->toBe(SignupStatus::Approved);
    $this->withSession([SignupApplicationPage::SESSION_KEY => $application->reference])->get('/admin/signup/application')
        ->assertOk()->assertSee($application->reference)->assertSee('being set up')
        ->assertDontSee('priya@acme-signup.test');
    expect($application->fresh()->token_hash)->toBeNull(); // consumed: no live link exists to leak
});

it('refuses details without the acknowledgements, and shows nothing without the confirmed session', function () {
    signupCatalogue();
    signupStart();
    $application = app(SignupApplications::class)->confirm(lastSignupToken());

    $this->get('/admin/signup/application')->assertOk()->assertSee('Open the link we e-mailed you')->assertDontSee($application->reference);

    session([SignupApplicationPage::SESSION_KEY => $application->reference]);
    Livewire::test(SignupApplicationPage::class)
        ->fillForm(['legal_name' => 'Acme Signup Private Limited', 'display_name' => 'Acme Signup', 'timezone' => 'Asia/Kolkata', 'intended_employees' => 120,
            'accept_terms' => false, 'accept_privacy' => true, 'authority' => true])
        ->call('submitDetails')->assertHasFormErrors(['accept_terms']);
    expect($application->fresh()->status)->toBe(SignupStatus::DetailsPending);
});

it('asks a signed-in person to sign out instead of registering', function () {
    signupCatalogue();
    $tenant = provisionTenant('Signed In Co');
    $this->actingAs(tenantUser($tenant, ['company.view']));

    Livewire::test(SignupStart::class)->assertSee('Sign out first')->assertDontSee('Send me a confirmation link');
});

it('shows the operators readiness and applications, and refuses everyone else', function () {
    $setup = signupCatalogue();
    signupPolicy(ConfigurationKey::SignupReview, 'all', platformAdmin(), platformAdmin());
    $application = signupSubmitted();
    $tenant = provisionTenant('Customer');

    $this->actingAs(tenantUser($tenant, ['*']));
    $this->get(PlatformSignupsPage::getUrl())->assertForbidden();

    $operator = platformAdmin();
    $this->actingAs($operator);
    proveMfa($operator);
    $this->get(PlatformSignupsPage::getUrl())->assertOk()->assertSee('Can signup work today?')->assertSee('core in IN')->assertSee($application->reference);

    Livewire::test(PlatformSignupsPage::class)->set('application', $application->id)
        ->assertSee('REVIEW_POLICY')->assertSee('Eligible now')->assertDontSee('acknowledged_ip')->assertDontSee('203.0.113.7')
        ->callAction('approve', data: ['reason' => 'Known prospect, contract signed'])
        ->assertHasNoActionErrors();

    expect($application->fresh()->status)->toBe(SignupStatus::ActivationPending)->and($application->fresh()->decided_by)->toBe($operator->id);
});
