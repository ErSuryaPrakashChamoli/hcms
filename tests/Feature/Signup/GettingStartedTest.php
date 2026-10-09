<?php

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\InvitationLink;
use App\Domain\Identity\Services\UserInvitations;
use App\Domain\Organisation\Models\Company;
use App\Domain\Signup\Services\SignupMaintenance;
use App\Domain\Signup\Services\TenantSetupProgress;
use App\Filament\Pages\GettingStarted;
use App\Filament\Pages\Home;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

require_once __DIR__.'/SignupTestHelpers.php';

/*
 | SaaS.10: the first run. The Account Owner sees separate states and the steps left, linked when they can do them.
 */

beforeEach(function () {
    Notification::fake();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    RateLimiter::clear('signup-start-ip:203.0.113.7');
    signupCatalogue();
    $application = signupSubmitted();
    app(SignupMaintenance::class)->run();
    $owner = User::query()->findOrFail($application->fresh()->provisioned_user_id);
    $token = null;
    Notification::assertSentTo($owner, InvitationLink::class, function (InvitationLink $n) use ($owner, &$token) {
        $token = basename((string) parse_url((string) $n->toMail($owner)->actionUrl, PHP_URL_PATH));

        return true;
    });
    app(UserInvitations::class)->accept($token, 'A-Long-Owner-Passphrase-2026!');
    $this->owner = $owner->fresh();
    $this->tenant = $this->owner->tenant;
    actAsTenant($this->tenant);
    $this->actingAs($this->owner);
});

it('keeps account, organisation, set-up, subscription and sensitive modules apart', function () {
    $states = collect(app(TenantSetupProgress::class)->for($this->owner)['states'])->keyBy('label');

    expect($states['Your account']['state'])->toBe('Active')
        ->and($states['Organisation in PeopleOS']['state'])->toBe('Trial')
        ->and($states['Organisation set-up']['state'])->toContain('of 9 steps')
        ->and($states['Subscription']['state'])->toBe('Trial')->and($states['Subscription']['detail'])->toContain('up to 500 active employees')
        ->and($states['Payroll and statutory calculations']['state'])->toBe('Not active')
        ->and($states['Payroll and statutory calculations']['detail'])->toContain('Nobody has the Payroll Admin role');
});

it('links the steps the owner can do, names the role for the others, and shows the progress on Home', function () {
    $component = Livewire::test(GettingStarted::class)->assertOk()
        ->assertSee('Confirm your company')->assertSee('Needs the')->assertSee('Attendance Admin');
    $items = collect(app(TenantSetupProgress::class)->for($this->owner)['items'])->keyBy('key');
    expect($items['company']['url'])->not->toBeNull()->and($items['working_days']['url'])->toBeNull()->and($items['working_days']['needs'])->toBe('Attendance Admin')
        ->and($items['policies']['url'])->not->toBeNull() // read only for the owner (policy.view)
        ->and($items['company']['done'])->toBeFalse();

    Livewire::test(Home::class)->assertSee('Finish setting up your organisation');
    expect(GettingStarted::shouldRegisterNavigation())->toBeTrue();

    Company::factory()->create();
    expect(collect(app(TenantSetupProgress::class)->for($this->owner)['items'])->firstWhere('key', 'company')['done'])->toBeTrue();
});

it('is for the organisation\'s administrators only; existing tenants\' Home shows no banner', function () {
    $employee = tenantUser($this->tenant, ['employee.self']);
    $this->actingAs($employee);
    expect(GettingStarted::canAccess())->toBeFalse();

    $other = provisionTenant('Operator Provisioned Co');
    actAsTenant($other);
    $this->actingAs(tenantUser($other, ['*']));
    expect(GettingStarted::canAccess())->toBeTrue()->and(GettingStarted::banner())->toBeNull()
        ->and(GettingStarted::shouldRegisterNavigation())->toBeFalse(); // existing tenants' navigation is unchanged
    Livewire::test(Home::class)->assertDontSee('Finish setting up your organisation');
});
