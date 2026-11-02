<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\TemporaryPasswordMail;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Filament\Auth\ChangeExpiredPassword;
use App\Filament\Auth\Login;
use App\Filament\Pages\Home;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
| Owner decision 2026-10-31: sign-in starts with a temporary password — e-mailed (the invitation, or a fresh start for an
| existing user) or generated and shown once to an administrator — which the person replaces with their own at the first
| sign-in. Only its hash is stored; it expires; the e-mail is still confirmed.
*/

beforeEach(function () {
    $this->withoutDefer();
    Notification::fake();
    $this->tenant = provisionTenant('Alpha');
    $this->admin = tenantUser($this->tenant, ['user.view', 'user.create', 'user.update', 'user.assign_roles', 'security.manage', 'employee.view']);
    $this->actingAs($this->admin);
    actAsTenant($this->tenant);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/** The temporary password from the latest e-mail to the user. */
function mailedPassword(User $user): string
{
    $password = '';
    Notification::assertSentTo($user, TemporaryPasswordMail::class, function (TemporaryPasswordMail $mail) use ($user, &$password) {
        $line = collect($mail->toMail($user)->introLines)->first(fn (string $l) => str_starts_with($l, 'Temporary password: '));
        $password = substr((string) $line, strlen('Temporary password: '));

        return true;
    });

    return $password;
}

function signInAs(string $email, string $password)
{
    Auth::guard('web')->logout();

    return Livewire::test(Login::class)->set('data.email', $email)->set('data.password', $password)->call('authenticate');
}

it('invites a new user with an e-mailed temporary password, which they replace with their own at the first sign-in', function () {
    Livewire::test(CreateUser::class)->fillForm(['name' => 'Neha Kapoor', 'email' => 'neha@alpha.test'])->call('create')->assertHasNoFormErrors();
    $neha = User::query()->where('email', 'neha@alpha.test')->sole();
    $temporary = mailedPassword($neha);

    expect($neha->status)->toBe(UserStatus::Invited)->and($neha->mustReplacePassword())->toBeTrue()->and($neha->temporary_password_via)->toBe('email')
        ->and(strlen($temporary))->toBeGreaterThanOrEqual(14)->and(Hash::check($temporary, $neha->password))->toBeTrue();
    // Audited without the password.
    $event = AuditEvent::query()->withoutTenancy()->where('action', 'TEMPORARY_PASSWORD_ISSUED')->where('entity_id', (string) $neha->id)->sole();
    expect($event->metadata['via'])->toBe('email')->and(json_encode($event->metadata))->not->toContain($temporary);

    // Signing in with it leads to choosing their own password before anything else.
    signInAs('neha@alpha.test', $temporary)->assertHasNoErrors();
    actAsTenant($this->tenant);
    $this->get(Home::getUrl())->assertRedirect(route('filament.admin.auth.password-expired'));
    Livewire::test(ChangeExpiredPassword::class)->assertSee('Choose your own password')->assertSee('Temporary password')
        ->set('data.currentPassword', $temporary)->set('data.password', 'Neha-Own-Pass-42')->set('data.passwordConfirmation', 'Neha-Own-Pass-42')
        ->call('change')->assertHasNoErrors()->assertRedirect();

    $neha->refresh();
    expect($neha->status)->toBe(UserStatus::Active)->and($neha->mustReplacePassword())->toBeFalse()
        ->and($neha->hasVerifiedEmail())->toBeTrue() // the e-mailed password proved the address
        ->and(Hash::check('Neha-Own-Pass-42', $neha->password))->toBeTrue();
    $this->get(Home::getUrl())->assertOk();
});

it('e-mails an existing user a new temporary password: the old password stops working and their sessions end', function () {
    $asha = tenantUser($this->tenant, ['employee.view'], ['email' => 'asha@alpha.test', 'password' => 'Old-Password-1']);
    $epoch = (int) $asha->session_epoch;

    Livewire::test(EditUser::class, ['record' => $asha->id])->assertActionVisible('emailTemporaryPassword')->callAction('emailTemporaryPassword')->assertHasNoActionErrors();
    $asha->refresh();
    $temporary = mailedPassword($asha);

    expect(Hash::check('Old-Password-1', $asha->password))->toBeFalse()->and(Hash::check($temporary, $asha->password))->toBeTrue()
        ->and($asha->status)->toBe(UserStatus::Active)->and($asha->mustReplacePassword())->toBeTrue()
        ->and((int) $asha->session_epoch)->toBeGreaterThan($epoch);
    signInAs('asha@alpha.test', 'Old-Password-1')->assertHasErrors();
    signInAs('asha@alpha.test', $temporary)->assertHasNoErrors();
    actAsTenant($this->tenant);
    $this->get(Home::getUrl())->assertRedirect(route('filament.admin.auth.password-expired'));
});

it('generates a temporary password shown once, only to an administrator who may also reset MFA', function () {
    Livewire::test(CreateUser::class)->fillForm(['name' => 'Ravi Shah', 'email' => 'ravi@alpha.test'])->call('create')->assertHasNoFormErrors();
    $ravi = User::query()->where('email', 'ravi@alpha.test')->sole();

    $helper = tenantUser($this->tenant, ['user.view', 'user.update']);
    $this->actingAs($helper);
    Livewire::test(EditUser::class, ['record' => $ravi->id])->assertActionVisible('emailTemporaryPassword')->assertActionHidden('generateTemporaryPassword');

    $this->actingAs($this->admin);
    $page = Livewire::test(EditUser::class, ['record' => $ravi->id])->callAction('generateTemporaryPassword')->assertActionMounted('temporaryPasswordShown');
    $shown = (string) $page->get('mountedActions')[0]['arguments']['password'];
    $page->assertMountedActionModalSee([$shown, 'This is the only time it is shown']);

    $ravi->refresh();
    expect(Hash::check($shown, $ravi->password))->toBeTrue()->and($ravi->temporary_password_via)->toBe('shown')
        ->and(Hash::check(mailedPassword($ravi), $ravi->password))->toBeFalse(); // the invitation e-mailed earlier stops working

    // Handed over in person: choosing their own password confirms the address too (owner decision 2026-11-01), so the
    // person goes straight in, never to "Verify your email address".
    signInAs('ravi@alpha.test', $shown)->assertHasNoErrors();
    actAsTenant($this->tenant);
    Livewire::test(ChangeExpiredPassword::class)
        ->set('data.currentPassword', $shown)->set('data.password', 'Ravi-Own-Pass-42')->set('data.passwordConfirmation', 'Ravi-Own-Pass-42')
        ->call('change')->assertHasNoErrors();
    expect($ravi->refresh()->status)->toBe(UserStatus::Active)->and($ravi->hasVerifiedEmail())->toBeTrue();
    $this->get(Home::getUrl())->assertOk();
});

it('stops a temporary password from signing in once it expires, and on Revoke invitation', function () {
    Livewire::test(CreateUser::class)->fillForm(['name' => 'Late Comer', 'email' => 'late@alpha.test'])->call('create')->assertHasNoFormErrors();
    $late = User::query()->where('email', 'late@alpha.test')->sole();
    $temporary = mailedPassword($late);

    $this->travel(73)->hours();
    expect($late->refresh()->holdsTemporaryPassword())->toBeFalse()->and($late->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
    signInAs('late@alpha.test', $temporary)->assertHasErrors();
    $this->travelBack();

    $this->actingAs($this->admin);
    actAsTenant($this->tenant);
    Livewire::test(EditUser::class, ['record' => $late->id])->callAction('emailTemporaryPassword')->assertHasNoActionErrors();
    $fresh = mailedPassword($late->refresh());
    Livewire::test(EditUser::class, ['record' => $late->id])->callAction('revokeInvitation', data: ['reason' => 'Hired by mistake'])->assertHasNoActionErrors();
    expect(Hash::check($fresh, $late->refresh()->password))->toBeFalse()->and($late->mustReplacePassword())->toBeFalse();
    signInAs('late@alpha.test', $fresh)->assertHasErrors();
});

it('invites an employee to sign in from their profile, then offers a new temporary password', function () {
    $employee = forceLifecycle(Employee::factory()->create(['work_email' => 'kiran@alpha.test']), LifecycleState::Active);

    Livewire::test(ViewEmployee::class, ['record' => $employee->id])->assertActionVisible('inviteToSignIn')->assertActionHidden('emailTemporaryPassword')
        ->callAction('inviteToSignIn')->assertHasNoActionErrors();

    $kiran = User::query()->where('email', 'kiran@alpha.test')->sole();
    expect($employee->refresh()->user_id)->toBe($kiran->id)->and($kiran->status)->toBe(UserStatus::Invited)->and($kiran->roles()->pluck('slug')->all())->toContain('employee');
    mailedPassword($kiran);

    Livewire::test(ViewEmployee::class, ['record' => $employee->id])->assertActionHidden('inviteToSignIn')
        ->assertActionVisible('emailTemporaryPassword')->assertActionVisible('generateTemporaryPassword')->assertActionVisible('openUser');
});
