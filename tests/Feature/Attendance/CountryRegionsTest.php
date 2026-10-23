<?php

use App\Domain\Attendance\Models\CountryRegion;
use App\Domain\Attendance\Models\HolidayLibraryList;
use App\Domain\Attendance\Services\CountryRegions;
use App\Domain\Attendance\Services\HolidayLibrary;
use App\Domain\Audit\Models\AuditEvent;
use App\Filament\Pages\PlatformRegionsPage;
use Livewire\Livewire;

/*
| The platform's fixed region list per country, pre-filled from ISO 3166-2. Holiday uploads may only use its codes and
| take its names; tenant locations are matched to it. Regions are retired, never deleted.
*/

beforeEach(function () {
    $this->operator = platformAdmin();
    $this->regions = app(CountryRegions::class);
    $this->library = app(HolidayLibrary::class);
});

function countryRegion(string $country, string $code): CountryRegion
{
    return CountryRegion::query()->where('country_code', $country)->where('code', $code)->sole();
}

it('comes pre-filled with the top-level ISO 3166-2 regions of every country, with readable names', function () {
    expect(CountryRegion::query()->count())->toBe(collect(CountryRegions::standard())->sum(fn (array $regions) => count($regions)))
        ->and(CountryRegion::query()->distinct()->count('country_code'))->toBe(200)
        ->and(count($this->regions->active('IN')))->toBe(36)
        ->and(count($this->regions->active('US')))->toBe(57)
        // The UK's four nations, not its councils; bracketed alternates dropped.
        ->and(array_values($this->regions->active('GB')))->toBe(['England', 'Northern Ireland', 'Scotland', 'Wales'])
        // Indian names without transliteration marks; other countries keep their own spelling.
        ->and(countryRegion('IN', 'MH')->name)->toBe('Maharashtra')
        ->and(countryRegion('DE', 'BW')->name)->toBe('Baden-Württemberg')
        ->and(countryRegion('IN', 'MH')->source)->toBe(CountryRegion::SOURCE_ISO)
        ->and(countryRegion('IN', 'MH')->isoCode())->toBe('IN-MH');
});

it('matches free text to a region by code, prefixed code or name, ignoring case and accents', function () {
    foreach (['IN-MH', 'mh', 'Maharashtra', '  maharashtra ', 'Mahārāshtra'] as $value) {
        expect($this->regions->match('IN', $value))->toBe('MH', $value);
    }
    expect($this->regions->match('IN', 'Telangana'))->toBe('TS')
        ->and($this->regions->match('IN', 'Bombay'))->toBeNull()
        ->and($this->regions->match('IN', ''))->toBeNull()
        ->and($this->regions->match('US', 'US-CA'))->toBe('CA')
        ->and($this->regions->match('CA', 'Ontario'))->toBe('ON');
});

it('lets operators add, rename, retire and restore regions, and uploads follow the list', function () {
    $added = $this->regions->add('in', 'IN-xy', ' Example Region ', $this->operator);
    expect($added->code)->toBe('XY')->and($added->name)->toBe('Example Region')->and($added->source)->toBe(CountryRegion::SOURCE_PLATFORM)
        ->and(fn () => $this->regions->add('IN', 'MH', 'Again', $this->operator))->toThrow(RuntimeException::class, 'IN-MH is already on the list as Maharashtra.')
        ->and(fn () => $this->regions->add('IN', 'M H', 'Spaced', $this->operator))->toThrow(InvalidArgumentException::class, 'A region code is up to 16 letters or digits')
        ->and(fn () => $this->regions->add('XX', 'AA', 'Nowhere', $this->operator))->toThrow(InvalidArgumentException::class, "'XX' is not a country");

    expect($this->library->upload('IN', 2027, "date,name,region_code\n2027-06-01,Example Day,XY", null, $this->operator)->sole()->region_name)->toBe('Example Region');

    // A rename reaches the holiday lists already uploaded for the region.
    $this->regions->rename($added->fresh(), 'Example Province', $this->operator);
    expect(HolidayLibraryList::query()->where('region_code', 'XY')->sole()->region_name)->toBe('Example Province');

    $this->regions->retire($added->fresh(), $this->operator);
    expect(fn () => $this->library->upload('IN', 2027, "date,name,region_code\n2027-06-01,Example Day,XY", null, $this->operator))
        ->toThrow(InvalidArgumentException::class, "region_code 'XY' is not on the region list for India")
        ->and(fn () => $this->regions->add('IN', 'XY', 'Again', $this->operator))->toThrow(RuntimeException::class, 'IN-XY is already on the list as Example Province (retired): restore it instead.')
        ->and(fn () => $this->regions->retire($added->fresh(), $this->operator))->toThrow(RuntimeException::class, 'IN-XY is already retired.')
        // Its list stays.
        ->and(HolidayLibraryList::query()->where('region_code', 'XY')->exists())->toBeTrue();

    $this->regions->restore($added->fresh(), $this->operator);
    expect($this->library->upload('IN', 2027, "date,name,region_code\n2027-06-02,Example Day,XY", null, $this->operator)->sole()->entries->pluck('name')->all())->toBe(['Example Day']);

    $events = AuditEvent::query()->withoutTenancy()->where('module', 'attendance')->where('entity_label', 'like', 'Region IN-XY%')->pluck('action')->map->value->all();
    expect($events)->toBe(['CREATE', 'UPDATE', 'STATUS_CHANGE', 'STATUS_CHANGE']);
});

it('lets only platform operators change the region list', function () {
    $tenant = provisionTenant('Region Co');
    actAsTenant($tenant);
    $user = tenantUser($tenant, ['*']);
    expect(fn () => $this->regions->add('IN', 'XY', 'Example', $user))->toThrow(RuntimeException::class, 'Only platform operators can manage regions.')
        ->and(fn () => $this->regions->rename(countryRegion('IN', 'MH'), 'Renamed', $user))->toThrow(RuntimeException::class, 'Only platform operators can manage regions.')
        ->and(fn () => $this->regions->retire(countryRegion('IN', 'MH'), $user))->toThrow(RuntimeException::class, 'Only platform operators can manage regions.')
        ->and(fn () => $this->regions->syncStandard('IN', $user))->toThrow(RuntimeException::class, 'Only platform operators can manage regions.');
    actAsTenant(null);

    $this->actingAs($user)->get(PlatformRegionsPage::getUrl())->assertForbidden();
    $this->actingAs($this->operator)->get(PlatformRegionsPage::getUrl())->assertOk()->assertSee('IN-MH')->assertSee('Maharashtra');
});

it('adds missing standard regions without touching renamed or retired ones', function () {
    countryRegion('IN', 'GA')->delete();
    $this->regions->rename(countryRegion('IN', 'MH'), 'Maharashtra State', $this->operator);
    $this->regions->retire(countryRegion('IN', 'KA'), $this->operator);

    expect($this->regions->syncStandard('IN', $this->operator))->toBe(1)
        ->and(countryRegion('IN', 'GA')->name)->toBe('Goa')
        ->and(countryRegion('IN', 'MH')->name)->toBe('Maharashtra State')
        ->and(countryRegion('IN', 'KA')->status)->toBe(CountryRegion::RETIRED)
        ->and($this->regions->syncStandard(null, $this->operator))->toBe(0);
});

it('runs the region list from the operators\' page', function () {
    $this->actingAs($this->operator);
    Livewire::test(PlatformRegionsPage::class)
        ->assertSee('IN-MH')->assertActionHidden('syncStandard')
        ->set('country', 'GB')->assertSee('GB-SCT')->assertDontSee('IN-MH')
        ->callAction('addRegion', data: ['code' => 'GB-ZZZ', 'name' => 'Test Isle'])->assertNotified('Added GB-ZZZ Test Isle')
        ->assertSee('Test Isle')->assertSee('Added by the platform');

    $region = countryRegion('GB', 'ZZZ');
    Livewire::test(PlatformRegionsPage::class)->set('country', 'GB')
        ->callAction('rename', data: ['name' => 'Test Island'], arguments: ['region' => $region->id])->assertNotified('Renamed')->assertSee('Test Island')
        ->callAction('retire', arguments: ['region' => $region->id])->assertNotified('Retired')
        ->callAction('restore', arguments: ['region' => $region->id])->assertNotified('Restored');
    expect($region->fresh()->name)->toBe('Test Island')->and($region->fresh()->status)->toBe(CountryRegion::ACTIVE);

    countryRegion('GB', 'WLS')->delete();
    Livewire::test(PlatformRegionsPage::class)->set('country', 'GB')
        ->assertActionVisible('syncStandard')
        ->callAction('syncStandard')->assertNotified('1 region added');
    expect(countryRegion('GB', 'WLS')->name)->toBe('Wales');
});
