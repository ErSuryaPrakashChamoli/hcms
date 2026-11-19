<?php

use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Support\AddressRules;
use App\Filament\Resources\Companies\Pages\CreateCompany;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Support\Geography\Currencies;
use Livewire\Livewire;

beforeEach(function () {
    $this->tenant = provisionTenant('Locale Co');
    $this->actingAs(tenantUser($this->tenant, ['company.*']));
    actAsTenant($this->tenant);
});

it('asks for an Indian company\'s state and city, and fills in its time zone and currency', function () {
    $page = Livewire::test(CreateCompany::class)
        ->fillForm(['name' => 'Gamma Co', 'code' => 'GAMMA', 'country_code' => 'IN'])
        ->assertFormSet(['timezone' => 'Asia/Kolkata', 'currency' => 'INR'])
        ->call('create')
        ->assertHasFormErrors(['state_code' => 'required', 'city' => 'required']);

    $page->fillForm(['state_code' => 'MH', 'city' => 'Mumbai'])->call('create')->assertHasNoFormErrors();

    expect(Company::query()->where('code', 'GAMMA')->first())
        ->state_code->toBe('MH')->city->toBe('Mumbai')->timezone->toBe('Asia/Kolkata')->currency->toBe('INR');
});

it('moves the time zone and currency with the country and clears the state and city', function () {
    Livewire::test(CreateCompany::class)
        ->fillForm(['country_code' => 'IN', 'state_code' => 'MH', 'city' => 'Mumbai'])
        ->fillForm(['country_code' => 'US'])
        ->assertFormSet(['state_code' => null, 'city' => null, 'timezone' => 'America/New_York', 'currency' => 'USD'])
        ->fillForm(['country_code' => 'AE'])
        ->assertFormSet(['timezone' => 'Asia/Dubai', 'currency' => 'AED']);
});

it('only offers the chosen country\'s states, time zones and currencies on the lists', function () {
    Livewire::test(CreateCompany::class)
        ->fillForm(['name' => 'Delta Co', 'code' => 'DELTA', 'country_code' => 'IN', 'state_code' => 'NSW', 'city' => 'Pune', 'timezone' => 'Europe/London', 'currency' => 'ABC'])
        ->call('create')
        ->assertHasFormErrors(['state_code', 'timezone', 'currency']);

    expect(Company::query()->where('code', 'DELTA')->exists())->toBeFalse();
});

it('asks no state or city of a city-state, and keeps them optional where the state is not a jurisdiction', function () {
    Livewire::test(CreateCompany::class)
        ->fillForm(['name' => 'Lion Co', 'code' => 'LION', 'country_code' => 'SG'])
        ->assertFormFieldHidden('state_code')
        ->assertFormFieldHidden('city')
        ->call('create')
        ->assertHasNoFormErrors();

    Livewire::test(CreateCompany::class)
        ->fillForm(['name' => 'Thames Co', 'code' => 'THAMES', 'country_code' => 'GB'])
        ->assertFormFieldVisible('state_code')
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Company::query()->where('code', 'LION')->first())->timezone->toBe('Asia/Singapore')->currency->toBe('SGD')->state_code->toBeNull()
        ->and(Company::query()->where('code', 'THAMES')->first())->timezone->toBe('Europe/London')->currency->toBe('GBP')->state_code->toBeNull();
});

it('opens a company saved without a time zone or currency with its country\'s, and never replaces saved ones', function () {
    $blank = Company::factory()->create(['country_code' => 'IN', 'timezone' => null, 'currency' => null]);
    $saved = Company::factory()->create(['country_code' => 'IN', 'timezone' => 'UTC', 'currency' => 'USD']);

    Livewire::test(EditCompany::class, ['record' => $blank->getRouteKey()])
        ->assertFormSet(['country_code' => 'IN', 'timezone' => 'Asia/Kolkata', 'currency' => 'INR']);
    Livewire::test(EditCompany::class, ['record' => $saved->getRouteKey()])
        ->assertFormSet(['timezone' => 'UTC', 'currency' => 'USD']);
});

it('reads each country\'s currency and address rules from ICU and the region list', function () {
    expect(Currencies::forCountry('IN'))->toBe('INR')
        ->and(Currencies::forCountry('ae'))->toBe('AED')
        ->and(Currencies::forCountry('DE'))->toBe('EUR')
        ->and(Currencies::forCountry('AQ'))->toBeNull()
        ->and(Currencies::forCountry('India'))->toBeNull()
        ->and(Currencies::options())->toHaveKey('INR', 'INR · Indian Rupee')->not->toHaveKey('XXX')
        ->and(Currencies::options('DEM'))->toHaveKey('DEM')
        ->and(AddressRules::stateRequired('IN'))->toBeTrue()
        ->and(AddressRules::stateRequired('GB'))->toBeFalse()
        ->and(AddressRules::hasState('SG'))->toBeFalse()
        ->and(AddressRules::cityRequired('US'))->toBeTrue()
        ->and(AddressRules::stateLabel('CA'))->toBe('Province');
});
