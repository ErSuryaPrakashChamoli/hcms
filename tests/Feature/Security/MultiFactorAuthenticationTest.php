<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Services\Documents;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\MultiFactor;
use App\Domain\Identity\Services\SessionSecurity;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Services\PlatformTenantAccess;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Auth\ConfirmMultiFactorAuthentication;
use App\Filament\Auth\Login;
use App\Filament\Pages\Home;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Support\Tenancy\TenantContext;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

/*
| SaaS.2 §5.1: a tenant's "MFA required" is enforced on the server at every request and every way in;
| people can enrol, recover and be reset; every change is audited without secrets.
*/

beforeEach(function () {
    $this->tenant = provisionTenant('Alpha');
    $this->other = provisionTenant('Beta');
    $this->user = tenantUser($this->tenant, ['employee.view']);
    $this->otherUser = tenantUser($this->other, ['employee.view']);
    $this->setUpRoute = route('filament.admin.auth.multi-factor-authentication.set-up-required');
    $this->challengeRoute = route('filament.admin.auth.multi-factor-authentication.challenge');
});

function requireMfa(Tenant $tenant, bool $on = true): void
{
    app(TenantContext::class)->runAs($tenant, fn () => app(SettingsRepository::class)->set('security.mfa_required', $on, 'Test policy'));
}

/** Gives the user an authenticator as the set-up flow would, and returns its secret. */
function enrol(User $user): string
{
    $secret = app(AppAuthentication::class)->generateSecret();
    $user->saveAppAuthenticationSecret($secret);
    app(AppAuthentication::class)->saveRecoveryCodes($user, ['recovery-one-111', 'recovery-two-222']);

    return $secret;
}

function currentCode(string $secret): string
{
    return app(Google2FA::class)->getCurrentOtp($secret);
}

/** A sign-in that did not pass the login challenge (remember-me cookie, SSO): the Login event fires, nothing marks MFA. */
function signInWithoutChallenge(User $user): void
{
    Auth::guard('web')->login($user->fresh());
}

it('sends a user without an authenticator to set one up when their tenant requires MFA, on every request', function () {
    requireMfa($this->tenant);
    signInWithoutChallenge($this->user);

    $this->get(Home::getUrl())->assertRedirect($this->setUpRoute);
    $this->get($this->setUpRoute)->assertOk();
});

it('lets the same user in when the tenant does not require MFA, and never applies one tenant\'s policy to another', function () {
    requireMfa($this->tenant);
    signInWithoutChallenge($this->otherUser);
    $this->get(Home::getUrl())->assertOk();

    requireMfa($this->tenant, false);
    signInWithoutChallenge($this->user);
    $this->get(Home::getUrl())->assertOk();
});

it('challenges a session that was opened without the login challenge (remember-me, SSO)', function () {
    $secret = enrol($this->user);
    signInWithoutChallenge($this->user);

    $this->get(Home::getUrl())->assertRedirect($this->challengeRoute);
    actAsTenant($this->tenant);
    Livewire::test(ConfirmMultiFactorAuthentication::class)->set('data.app.code', '000000')->call('confirm')->assertHasErrors();
    expect(app(MultiFactor::class)->isVerified(session()->driver(), $this->user))->toBeFalse();

    Livewire::test(ConfirmMultiFactorAuthentication::class)->set('data.app.code', currentCode($secret))->call('confirm')->assertHasNoErrors();
    $this->get(Home::getUrl())->assertOk();
});

it('accepts a recovery code once, and audits its use without the code', function () {
    enrol($this->user);
    signInWithoutChallenge($this->user);
    actAsTenant($this->tenant);

    Livewire::test(ConfirmMultiFactorAuthentication::class)->set('data.app.useRecoveryCode', true)->set('data.app.recoveryCode', 'recovery-one-111')->call('confirm')->assertHasNoErrors();
    $this->get(Home::getUrl())->assertOk();

    signInWithoutChallenge($this->user);
    Livewire::test(ConfirmMultiFactorAuthentication::class)->set('data.app.useRecoveryCode', true)->set('data.app.recoveryCode', 'recovery-one-111')->call('confirm')->assertHasErrors();

    $used = AuditEvent::query()->withoutTenancy()->where('action', 'MFA_CHANGED')->get()->first(fn ($e) => ($e->metadata['event'] ?? null) === 'recovery_code_used');
    expect($used)->not->toBeNull()->and(json_encode($used->metadata))->not->toContain('recovery-one-111');
});

it('challenges at password sign-in and remembers the proof for that session only', function () {
    $secret = enrol($this->user);
    $this->user->forceFill(['password' => 'Correct-Horse-9'])->save();

    $login = Livewire::test(Login::class)->set('data.email', $this->user->email)->set('data.password', 'Correct-Horse-9')->call('authenticate');
    $this->assertGuest();

    $login->set('data.multiFactor.app.code', currentCode($secret))->call('authenticate');
    $this->assertAuthenticatedAs($this->user);
    expect(app(MultiFactor::class)->isVerified(session()->driver(), $this->user))->toBeTrue();
    $this->get(Home::getUrl())->assertOk();
});

it('refuses a second set-up that would replace an authenticator in use, and audits enrolment without the secret', function () {
    $secret = enrol($this->user);

    expect(fn () => $this->user->fresh()->saveAppAuthenticationSecret(app(AppAuthentication::class)->generateSecret()))->toThrow(ValidationException::class);
    expect($this->user->fresh()->getAppAuthenticationSecret())->toBe($secret);

    $enabled = AuditEvent::query()->withoutTenancy()->where('action', 'MFA_CHANGED')->where('entity_id', (string) $this->user->id)->get();
    expect($enabled->filter(fn ($e) => ($e->metadata['event'] ?? null) === 'enabled'))->toHaveCount(1)
        ->and($enabled->first()->tenant_id)->toBe($this->tenant->id);
    $stored = AuditEvent::query()->withoutTenancy()->with('fieldChanges')->get()->toJson();
    expect($stored)->not->toContain($secret)->not->toContain('recovery-one-111');
});

it('lets a security administrator reset a lost authenticator, with a reason, ending the person\'s sessions', function () {
    enrol($this->user);
    $session = [SessionSecurity::SESSION_KEY => app(SessionSecurity::class)->fingerprint($this->user->fresh()), MultiFactor::SESSION_KEY => $this->user->id];
    $admin = tenantUser($this->tenant, ['user.view', 'user.update', 'security.manage']);
    $clerk = tenantUser($this->tenant, ['user.view', 'user.update']);
    actAsTenant($this->tenant);

    $this->actingAs($clerk);
    Livewire::test(EditUser::class, ['record' => $this->user->id])->assertActionHidden('resetMfa');

    $this->actingAs($admin);
    Livewire::test(EditUser::class, ['record' => $this->user->id])->callAction('resetMfa', data: ['reason' => 'Phone lost, ticket HR-77']);

    expect($this->user->fresh()->hasMfaEnabled())->toBeFalse();
    $reset = AuditEvent::query()->withoutTenancy()->where('action', 'MFA_CHANGED')->get()->first(fn ($e) => ($e->metadata['event'] ?? null) === 'reset_by_administrator');
    expect($reset->actor_id)->toBe($admin->id)->and($reset->reason)->toBe('Phone lost, ticket HR-77');

    $this->withSession($session)->actingAs($this->user->fresh())->get(Home::getUrl())->assertRedirect(filament()->getPanel('admin')->getLoginUrl());
});

it('never lets an administrator reset their own authenticator or another tenant\'s', function () {
    $admin = tenantUser($this->tenant, ['user.view', 'user.update', 'security.manage']);
    enrol($admin);
    enrol($this->otherUser);

    expect(fn () => app(MultiFactor::class)->reset($admin, 'self', $admin))->toThrow(RuntimeException::class)
        ->and(fn () => app(MultiFactor::class)->reset($this->otherUser, 'cross tenant', $admin))->toThrow(RuntimeException::class);
    expect($admin->fresh()->hasMfaEnabled())->toBeTrue()->and($this->otherUser->fresh()->hasMfaEnabled())->toBeTrue();
});

it('requires platform operators to use an authenticator under the platform policy, not the tenant\'s', function () {
    config(['peopleos.security.platform_mfa_required' => true]);
    $operator = platformAdmin();
    signInWithoutChallenge($operator);
    $this->get(Home::getUrl())->assertRedirect($this->setUpRoute);

    config(['peopleos.security.platform_mfa_required' => false]);
    requireMfa($this->tenant);
    app(PlatformTenantAccess::class)->enter($operator, $this->tenant, 'Support access for testing', null, session()->driver());
    $this->get(Home::getUrl())->assertOk();
});

it('enforces MFA on protected downloads too', function () {
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['*']));
    $employee = activeEmployee(null, ['document.own', 'task.view']);
    $url = app(Documents::class)->downloadUrl(app(Documents::class)->store($employee, UploadedFile::fake()->create('pan.pdf', 10, 'application/pdf'), DocumentType::query()->where('code', 'PAN')->first()));
    enrol($employee->user);
    actAsTenant(null);

    signInWithoutChallenge($employee->user);
    $this->get($url)->assertRedirect($this->challengeRoute);
});

it('keeps a suspended account out at the password step, before any challenge', function () {
    enrol($this->user);
    $this->user->forceFill(['password' => 'Correct-Horse-9', 'status' => 'suspended'])->save();

    Livewire::test(Login::class)->set('data.email', $this->user->email)->set('data.password', 'Correct-Horse-9')->call('authenticate')
        ->assertHasErrors(['data.email'])->assertSet('userUndertakingMultiFactorAuthentication', null);
    $this->assertGuest();
});

it('lets someone with server access remove an operator\'s lost authenticator, with a reason, audited', function () {
    $operator = platformAdmin();
    enrol($operator);

    $this->artisan('peopleos:mfa:reset', ['email' => $operator->email])->assertFailed();
    expect($operator->fresh()->hasMfaEnabled())->toBeTrue();

    $this->artisan('peopleos:mfa:reset', ['email' => $operator->email, '--reason' => 'Lost phone, identity confirmed by video call'])->assertSuccessful();
    expect($operator->fresh()->hasMfaEnabled())->toBeFalse();
    $reset = AuditEvent::query()->withoutTenancy()->where('action', 'MFA_CHANGED')->get()->last();
    expect($reset->tenant_id)->toBeNull()->and($reset->actor_id)->toBeNull()->and($reset->reason)->toBe('Lost phone, identity confirmed by video call');
});
