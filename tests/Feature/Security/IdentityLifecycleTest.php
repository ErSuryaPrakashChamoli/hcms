<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserInvitation;
use App\Domain\Identity\Notifications\InvitationLink;
use App\Domain\Identity\Notifications\PasswordResetLink;
use App\Domain\Identity\Services\InvalidInvitation;
use App\Domain\Identity\Services\PasswordResets;
use App\Domain\Identity\Services\UserInvitations;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Platform\Services\TenantSuspensions;
use App\Filament\Auth\AcceptInvitation;
use App\Filament\Auth\RequestPasswordReset;
use App\Filament\Auth\ResetPassword;
use App\Filament\Pages\Home;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Support\Tenancy\TenantContext;
use Filament\Auth\Notifications\VerifyEmail;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| SaaS.2 §5.2, §5.3, §6: password reset, invitations and e-mail verification, with no enumeration, no
| token reuse, no cross-tenant use and no privilege carried by a link.
*/

beforeEach(function () {
    $this->withoutDefer();
    Notification::fake();
    $this->tenant = provisionTenant('Alpha');
    $this->other = provisionTenant('Beta');
    $this->user = tenantUser($this->tenant, ['employee.view'], ['email' => 'asha@alpha.test', 'password' => 'Old-Password-1']);
    $this->otherUser = tenantUser($this->other, ['employee.view'], ['email' => 'ravi@beta.test']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function allEvents(string $action): Collection
{
    return AuditEvent::query()->withoutTenancy()->where('action', $action)->get();
}

/** The reset token the broker would e-mail (issued directly, so the test can use it). */
function resetToken(User $user): string
{
    return Password::broker()->createToken($user->fresh());
}

/* ---------- Password reset ---------- */

it('answers every reset request the same way and e-mails only an account that may sign in', function () {
    $suspended = tenantUser($this->tenant, [], ['email' => 'off@alpha.test', 'status' => UserStatus::Suspended]);
    $invited = tenantUser($this->tenant, [], ['email' => 'new@alpha.test', 'status' => UserStatus::Invited]);

    foreach (['asha@alpha.test', 'nobody@alpha.test', 'off@alpha.test', 'new@alpha.test'] as $email) {
        Livewire::test(RequestPasswordReset::class)->set('data.email', $email)->call('request')
            ->assertNotified(RequestPasswordReset::SENT);
        $this->travel(2)->minutes(); // past the page's per-address-and-IP limit
    }

    Notification::assertSentTo($this->user, PasswordResetLink::class);
    Notification::assertNotSentTo([$suspended, $invited], PasswordResetLink::class);
    expect(allEvents('PASSWORD_RESET_REQUESTED'))->toHaveCount(1);
});

it('limits reset requests from one place', function () {
    foreach ([1, 2] as $attempt) {
        Livewire::test(RequestPasswordReset::class)->set('data.email', "someone{$attempt}@alpha.test")->call('request')->assertNotified(RequestPasswordReset::SENT);
    }
    Livewire::test(RequestPasswordReset::class)->set('data.email', 'asha@alpha.test')->call('request')->assertNotNotified(RequestPasswordReset::SENT);
    Notification::assertNothingSent();
});

it('sends nothing for a suspended tenant', function () {
    app(TenantSuspensions::class)->suspend($this->tenant, 'Review', null);
    app(PasswordResets::class)->request('asha@alpha.test');

    Notification::assertNothingSent();
});

it('resets with a valid link once, proves the mailbox, ends every session and leaves MFA alone', function () {
    $this->user->forceFill(['email_verified_at' => null, 'remember_token' => 'cookie-token'])->save();
    $this->user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
    $epoch = $this->user->fresh()->getAttributes()['session_epoch'];
    $token = resetToken($this->user);

    Livewire::test(ResetPassword::class, ['email' => 'asha@alpha.test', 'token' => $token])
        ->set('password', 'New-Password-22')->set('passwordConfirmation', 'New-Password-22')->call('resetPassword')
        ->assertNotified('Your password has been changed. Sign in with it now.');

    $user = $this->user->fresh();
    expect(Hash::check('New-Password-22', $user->password))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->remember_token)->toBeNull()
        ->and($user->getAttributes()['session_epoch'])->toBe($epoch + 1)
        ->and($user->hasMfaEnabled())->toBeTrue()
        ->and(allEvents('PASSWORD_CHANGED')->sole()->metadata['method'])->toBe('reset_link'); // one event, from RecordAuthenticationEvents

    // Replay of the same link.
    Livewire::test(ResetPassword::class, ['email' => 'asha@alpha.test', 'token' => $token])
        ->set('password', 'Another-Password-3')->set('passwordConfirmation', 'Another-Password-3')->call('resetPassword')
        ->assertNotified(PasswordResets::INVALID);
    expect(Hash::check('New-Password-22', $this->user->fresh()->password))->toBeTrue();
});

it('gives one message for every bad link: unknown address, wrong token, expired token, another tenant\'s user', function () {
    $token = resetToken($this->user);
    $cases = [
        ['nobody@alpha.test', $token],
        ['asha@alpha.test', str_repeat('a', 64)],
        ['ravi@beta.test', $token],
    ];
    foreach ($cases as [$email, $t]) {
        expect(app(PasswordResets::class)->reset($email, $t, 'Fresh-Password-44'))->toBeFalse();
    }
    $this->travel(61)->minutes();
    expect(app(PasswordResets::class)->reset('asha@alpha.test', $token, 'Fresh-Password-44'))->toBeFalse()
        ->and(Hash::check('Old-Password-1', $this->user->fresh()->password))->toBeTrue()
        ->and(Hash::check('Old-Password-1', $this->otherUser->fresh()->password))->toBeFalse();
});

it('applies the tenant password policy only to a proven link, and keeps the link usable after a refusal', function () {
    app(TenantContext::class)->runAs($this->tenant, fn () => app(SettingsRepository::class)->set('security.password_min_length', 14, 'Policy'));
    $token = resetToken($this->user);

    // A weak password with a bad link reveals nothing about the policy (or the account).
    expect(app(PasswordResets::class)->reset('asha@alpha.test', str_repeat('b', 64), 'weak'))->toBeFalse();
    expect(fn () => app(PasswordResets::class)->reset('asha@alpha.test', $token, 'Short-Pass-1'))->toThrow(ValidationException::class);
    expect(app(PasswordResets::class)->reset('asha@alpha.test', $token, 'Long-Enough-Password-1'))->toBeTrue();
});

/* ---------- Invitations ---------- */

it('invites a new user instead of letting an administrator set a password', function () {
    $admin = tenantUser($this->tenant, ['user.view', 'user.create', 'user.update', 'user.assign_roles']);
    $this->actingAs($admin);
    actAsTenant($this->tenant);

    Livewire::test(CreateUser::class)->assertFormFieldDoesNotExist('password')
        ->fillForm(['name' => 'Neha Kapoor', 'email' => 'neha@alpha.test'])->call('create')->assertHasNoFormErrors();

    $neha = User::query()->where('email', 'neha@alpha.test')->sole();
    expect($neha->status)->toBe(UserStatus::Invited)
        ->and($neha->tenant_id)->toBe($this->tenant->id)
        ->and($neha->canAccessPanel(Filament::getPanel('admin')))->toBeFalse()
        ->and(UserInvitation::query()->where('user_id', $neha->id)->sole()->isPending())->toBeTrue();
    Notification::assertSentTo($neha, InvitationLink::class);
    expect(allEvents('INVITATION_ISSUED')->sole()->actor_id)->toBe($admin->id);
});

it('activates the account from the link once, with the tenant policy, and only then can the person sign in', function () {
    $invitee = tenantUser($this->tenant, [], ['email' => 'kavya@alpha.test', 'status' => UserStatus::Invited, 'email_verified_at' => null]);
    $token = null;
    Notification::fake();
    app(UserInvitations::class)->issue($invitee);
    Notification::assertSentTo($invitee, InvitationLink::class, function (InvitationLink $n) use (&$token) {
        $token = Str::afterLast($n->toMail(new stdClass)->actionUrl, '/');

        return true;
    });

    // The database holds only a hash of the token.
    expect(DB::table('user_invitations')->where('token_hash', $token)->exists())->toBeFalse()
        ->and(DB::table('user_invitations')->where('token_hash', hash('sha256', $token))->exists())->toBeTrue();

    Livewire::test(AcceptInvitation::class, ['token' => $token])->assertSet('valid', true)
        ->set('data.password', 'Kavya-Chooses-1')->set('data.passwordConfirmation', 'Kavya-Chooses-1')->call('accept')->assertRedirect(Filament::getLoginUrl());

    $kavya = $invitee->fresh();
    expect($kavya->status)->toBe(UserStatus::Active)
        ->and($kavya->email_verified_at)->not->toBeNull()
        ->and(Hash::check('Kavya-Chooses-1', $kavya->password))->toBeTrue()
        ->and($kavya->roles()->count())->toBe(0)
        ->and(allEvents('INVITATION_ACCEPTED')->sole()->actor_id)->toBe($kavya->id);

    // Replay: the link is spent.
    Livewire::test(AcceptInvitation::class, ['token' => $token])->assertSet('valid', false);
    expect(fn () => app(UserInvitations::class)->accept($token, 'Second-Try-Pass-1'))->toThrow(InvalidInvitation::class);
    $this->assertGuest();
});

it('refuses expired, revoked, superseded and suspended-tenant invitations with one message', function () {
    $invitee = tenantUser($this->tenant, [], ['email' => 'meera@alpha.test', 'status' => UserStatus::Invited]);
    $tokens = [];
    Notification::fake();
    $collect = function () use ($invitee, &$tokens) {
        Notification::assertSentTo($invitee, InvitationLink::class, function (InvitationLink $n) use (&$tokens) {
            $tokens[] = Str::afterLast($n->toMail(new stdClass)->actionUrl, '/');

            return true;
        });
    };

    app(UserInvitations::class)->issue($invitee);
    app(UserInvitations::class)->issue($invitee); // supersedes the first
    $collect();
    [$first, $second] = array_values(array_unique($tokens));
    expect(app(UserInvitations::class)->pending($first))->toBeNull()->and(app(UserInvitations::class)->pending($second))->not->toBeNull();

    app(TenantSuspensions::class)->suspend($this->tenant, 'Review', null);
    expect(app(UserInvitations::class)->pending($second))->toBeNull();
    app(TenantSuspensions::class)->reactivate($this->tenant, 'Cleared', null);

    $this->travel(73)->hours();
    expect(fn () => app(UserInvitations::class)->accept($second, 'Meera-Password-1'))->toThrow(InvalidInvitation::class, UserInvitations::INVALID);
    expect($invitee->fresh()->status)->toBe(UserStatus::Invited);
});

it('never attaches an invitation to someone already signed in, and never invites across tenants or to the platform', function () {
    $invitee = tenantUser($this->other, [], ['email' => 'zoe@beta.test', 'status' => UserStatus::Invited]);
    Notification::fake();
    app(UserInvitations::class)->issue($invitee);
    $token = null;
    Notification::assertSentTo($invitee, InvitationLink::class, function (InvitationLink $n) use (&$token) {
        $token = Str::afterLast($n->toMail(new stdClass)->actionUrl, '/');

        return true;
    });

    $this->actingAs($this->user);
    Livewire::test(AcceptInvitation::class, ['token' => $token])->assertSet('signedIn', true)->assertSet('valid', false)->call('accept');
    expect($invitee->fresh()->status)->toBe(UserStatus::Invited);

    expect(fn () => app(UserInvitations::class)->issue(platformAdmin()))->toThrow(RuntimeException::class)
        ->and(fn () => app(UserInvitations::class)->issue($this->user))->toThrow(RuntimeException::class);
});

it('re-invites at the new address when an administrator corrects an invited user\'s e-mail', function () {
    $invitee = tenantUser($this->tenant, [], ['email' => 'typo@alpha.test', 'status' => UserStatus::Invited]);
    app(UserInvitations::class)->issue($invitee);
    $admin = tenantUser($this->tenant, ['user.view', 'user.update']);
    $this->actingAs($admin);
    actAsTenant($this->tenant);

    Livewire::test(EditUser::class, ['record' => $invitee->id])->fillForm(['email' => 'right@alpha.test', 'audit_reason' => 'Typo'])->call('save')->assertHasNoFormErrors();

    $invitations = UserInvitation::query()->where('user_id', $invitee->id)->orderBy('id')->get();
    expect($invitations)->toHaveCount(2)
        ->and($invitations->first()->revoked_at)->not->toBeNull()
        ->and($invitations->last()->isPending())->toBeTrue();
    Notification::assertSentTo($invitee->fresh(), InvitationLink::class);
});

/* ---------- E-mail verification ---------- */

it('asks an active user to verify a changed address, and only that user\'s signed link verifies it', function () {
    $admin = tenantUser($this->tenant, ['user.view', 'user.update']);
    $this->actingAs($admin);
    actAsTenant($this->tenant);
    Livewire::test(EditUser::class, ['record' => $this->user->id])->fillForm(['email' => 'asha.rao@alpha.test', 'audit_reason' => 'Name change'])->call('save')->assertHasNoFormErrors();
    $asha = $this->user->fresh();
    expect($asha->email_verified_at)->toBeNull();
    Notification::assertSentTo($asha, VerifyEmail::class);

    Auth::guard('web')->login($asha);
    $this->get(Home::getUrl())->assertRedirect(route('filament.admin.auth.email-verification.prompt'));

    $link = URL::temporarySignedRoute('filament.admin.auth.email-verification.verify', now()->addMinutes(60), ['id' => $asha->id, 'hash' => sha1($asha->getEmailForVerification())]);
    // Another person's session cannot use it.
    Auth::guard('web')->login($this->otherUser->fresh());
    $this->get($link)->assertForbidden();

    Auth::guard('web')->login($asha->fresh());
    $this->get($link)->assertRedirect();
    expect($asha->fresh()->email_verified_at)->not->toBeNull()
        ->and(allEvents('EMAIL_VERIFIED')->sole()->actor_id)->toBe($asha->id);
    $this->get(Home::getUrl())->assertOk();
});

it('does not ask SSO sessions for local e-mail verification', function () {
    $this->user->forceFill(['email_verified_at' => null])->save();
    Auth::guard('web')->login($this->user->fresh());
    session()->put('auth.method', 'sso');

    $this->get(Home::getUrl())->assertOk();
});
