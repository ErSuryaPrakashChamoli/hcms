<?php

use App\Domain\Identity\Services\MultiFactor;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;

/* SaaS.2: every new identity page renders for the person it is meant for, and only for them. */

beforeEach(function () {
    $this->tenant = provisionTenant();
    $this->user = tenantUser($this->tenant, ['employee.view']);
    $this->panel = Filament::getPanel('admin');
});

it('renders the guest pages: sign-in with the reset link, reset request, reset form and an invitation', function () {
    $this->get($this->panel->getLoginUrl())->assertOk()->assertSee('password-reset/request', false);
    $this->get($this->panel->getRequestPasswordResetUrl())->assertOk();
    $this->get($this->panel->getResetPasswordUrl(Password::broker()->createToken($this->user), $this->user))->assertOk();
    $this->get(route('filament.admin.auth.invitation.accept', ['token' => str_repeat('x', 64)]))->assertOk()->assertSee('invalid or has expired');
});

it('renders the signed-in pages: account security, MFA challenge and e-mail verification', function () {
    Auth::guard('web')->login($this->user->fresh());
    $this->get($this->panel->getProfileUrl())->assertOk()->assertSee('Account security');

    $this->user->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
    Auth::guard('web')->login($this->user->fresh());
    $this->get(route('filament.admin.auth.multi-factor-authentication.challenge'))->assertOk()->assertSee('Confirm it is you');
    session()->put(MultiFactor::SESSION_KEY, $this->user->id);

    $this->user->forceFill(['email_verified_at' => null])->save();
    Auth::guard('web')->setUser($this->user->fresh());
    $this->get(route('filament.admin.auth.email-verification.prompt'))->assertOk();
});

it('keeps guests away from the signed-in pages', function () {
    $this->get($this->panel->getProfileUrl())->assertRedirect($this->panel->getLoginUrl());
    $this->get(route('filament.admin.auth.multi-factor-authentication.challenge'))->assertRedirect($this->panel->getLoginUrl());
    $this->get(route('filament.admin.auth.multi-factor-authentication.set-up-required'))->assertRedirect($this->panel->getLoginUrl());
});
