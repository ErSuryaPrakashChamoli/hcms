<?php

use App\Domain\Identity\Models\User;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Support\TimezoneFields;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
| The user form's time zone: choose the country first, then one of that country's zones. The country is not saved.
*/

beforeEach(function () {
    $this->withoutDefer();
    Notification::fake();
    $this->tenant = provisionTenant('Alpha');
    $this->admin = tenantUser($this->tenant, ['user.view', 'user.create', 'user.update', 'user.assign_roles']);
    $this->actingAs($this->admin);
    actAsTenant($this->tenant);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('lists a country\'s zones from the time-zone database and reads the country back from a zone', function () {
    expect(TimezoneFields::zones('IN'))->toBe(['Asia/Kolkata'])
        ->and(TimezoneFields::zones('US'))->toContain('America/New_York', 'America/Los_Angeles')
        ->and(TimezoneFields::zones(null))->toBe([])
        ->and(TimezoneFields::zones('ZZ'))->toBe([])
        ->and(TimezoneFields::countryOf('Europe/Berlin'))->toBe('DE')
        ->and(TimezoneFields::countryOf('UTC'))->toBeNull()
        ->and(TimezoneFields::countryOf('Not/AZone'))->toBeNull();
});

it('starts a new user at the organisation\'s country and fills its only zone', function () {
    Livewire::test(CreateUser::class)
        ->assertFormSet(['timezone_country' => 'IN', 'timezone' => 'Asia/Kolkata'])
        ->fillForm(['name' => 'Neha Kapoor', 'email' => 'neha@alpha.test'])->call('create')->assertHasNoFormErrors();

    expect(User::query()->where('email', 'neha@alpha.test')->sole()->timezone)->toBe('Asia/Kolkata');
});

it('offers only the chosen country\'s zones and clears the zone when the country changes', function () {
    Livewire::test(CreateUser::class)
        ->fillForm(['timezone_country' => 'US'])
        ->assertFormSet(['timezone' => null])
        ->fillForm(['name' => 'Sam Lee', 'email' => 'sam@alpha.test', 'timezone' => 'Asia/Kolkata'])->call('create')
        ->assertHasFormErrors(['timezone']);

    Livewire::test(CreateUser::class)
        ->fillForm(['timezone_country' => 'US'])
        ->fillForm(['name' => 'Sam Lee', 'email' => 'sam@alpha.test', 'timezone' => 'America/Chicago'])->call('create')
        ->assertHasNoFormErrors();

    expect(User::query()->where('email', 'sam@alpha.test')->sole()->timezone)->toBe('America/Chicago');
});

it('shows an existing user\'s country from their saved zone', function () {
    $user = tenantUser($this->tenant, ['employee.view'], ['email' => 'lena@alpha.test', 'timezone' => 'Europe/Berlin']);

    Livewire::test(EditUser::class, ['record' => $user->id])
        ->assertFormSet(['timezone_country' => 'DE', 'timezone' => 'Europe/Berlin']);
});
