<?php

use App\Domain\Identity\Enums\UserStatus;
use App\Filament\Auth\Login;
use App\Filament\Pages\Home;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

/*
| QA journey: the whole sign-in round trip a person takes, end to end — a guest is sent to the sign-in page, a wrong
| password is refused without saying which half was wrong, repeated guesses are throttled (the right password included),
| the right password opens Home, and signing out ends the session so the panel is closed again.
*/

beforeEach(function () {
    $this->tenant = provisionTenant('Journey');
    actAsTenant($this->tenant);
    $this->user = tenantUser($this->tenant, ['employee.self'], ['email' => 'journey@example.test', 'password' => 'Correct-Horse-9']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function attemptSignIn(string $email, string $password)
{
    return Livewire::test(Login::class)->set('data.email', $email)->set('data.password', $password)->call('authenticate');
}

it('sends a guest to the sign-in page, refuses a wrong password, signs in, and signs out for good', function () {
    $this->get(Home::getUrl())->assertRedirect(Filament::getPanel('admin')->getLoginUrl());
    $this->get(Filament::getPanel('admin')->getLoginUrl())->assertOk();

    attemptSignIn('journey@example.test', 'Wrong-Horse-1')->assertHasErrors(['data.email']);
    attemptSignIn('nobody@example.test', 'Correct-Horse-9')->assertHasErrors(['data.email']);
    $this->assertGuest();

    attemptSignIn('journey@example.test', 'Correct-Horse-9')->assertHasNoErrors();
    $this->assertAuthenticatedAs($this->user);
    $this->get(Home::getUrl())->assertOk();

    $this->post(route('filament.admin.auth.logout'))->assertRedirect();
    $this->assertGuest();
    $this->get(Home::getUrl())->assertRedirect(Filament::getPanel('admin')->getLoginUrl());
});

it('throttles repeated sign-in attempts, refusing even the right password until the window passes', function () {
    foreach (range(1, 5) as $attempt) {
        attemptSignIn('journey@example.test', "Wrong-Horse-{$attempt}")->assertHasErrors(['data.email']);
    }

    // The sixth attempt inside the minute is refused before the password is looked at.
    attemptSignIn('journey@example.test', 'Correct-Horse-9');
    $this->assertGuest();

    $this->travel(2)->minutes();
    attemptSignIn('journey@example.test', 'Correct-Horse-9')->assertHasNoErrors();
    $this->assertAuthenticatedAs($this->user);
});

it('closes the panel to a signed-in account once it is suspended, and refuses its next sign-in', function () {
    attemptSignIn('journey@example.test', 'Correct-Horse-9');
    $this->assertAuthenticatedAs($this->user);

    $this->user->forceFill(['status' => UserStatus::Suspended])->save();
    // The next request loads the account afresh from its session, as a browser's would.
    $this->actingAs($this->user->fresh());

    expect($this->get(Home::getUrl())->getStatusCode())->toBeIn([302, 403]);
    Auth::guard('web')->logout();
    attemptSignIn('journey@example.test', 'Correct-Horse-9');
    $this->assertGuest();
});
