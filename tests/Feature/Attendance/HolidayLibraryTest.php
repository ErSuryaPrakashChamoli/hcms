<?php

use App\Domain\Attendance\Enums\HolidayLibraryStatus;
use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\HolidayCalendar;
use App\Domain\Attendance\Models\HolidayLibraryEntry;
use App\Domain\Attendance\Models\HolidayLibraryList;
use App\Domain\Attendance\Services\HolidayLibrary;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use App\Filament\Pages\PlatformHolidayLibraryPage;
use App\Filament\Resources\HolidayCalendars\HolidayCalendarResource;
use App\Filament\Resources\HolidayCalendars\Pages\EditHolidayCalendar;
use App\Filament\Resources\HolidayCalendars\Pages\ListHolidayCalendars;
use App\Filament\Resources\HolidayCalendars\RelationManagers\HolidaysRelationManager;
use App\Filament\Support\HolidayLibraryPicker;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| Holiday library: the platform uploads holidays per country, region and year and publishes them; each tenant picks the
| holidays its organisation observes into its own calendar. Copies are the tenant's: library changes never reach them.
*/

const HOLIDAY_CSV = <<<'CSV'
date,name,type,half_day,region_code,region_name
2027-01-26,Republic Day,public,no,,
15 Aug 2027,Independence Day,,,,
2027-10-02,Gandhi Jayanti,public,no,,
2027-03-22,Holi,optional,no,,
2027-05-01,Maharashtra Day,public,no,MH,Maharashtra
2027-11-01,Karnataka Rajyotsava,public,yes,ka,
CSV;

beforeEach(function () {
    Storage::fake('local');
    $this->operator = platformAdmin();
    $this->library = app(HolidayLibrary::class);
    $this->tenant = provisionTenant('Holiday Co');
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['attendance.view', 'attendance.manage']);
    $this->viewer = tenantUser($this->tenant, ['attendance.view']);
    actAsTenant(null);
});

function publishedIndia2027(): void
{
    $library = app(HolidayLibrary::class);
    $operator = platformAdmin();
    foreach ($library->upload('IN', 2027, HOLIDAY_CSV, 'Gazette notification 2027', $operator) as $list) {
        $library->publish($list, $operator);
    }
}

function libraryEntry(string $name): HolidayLibraryEntry
{
    return HolidayLibraryEntry::query()->where('name', $name)->sole();
}

it('uploads a CSV as draft lists, one per region, with the national list for rows without a region', function () {
    $lists = $this->library->upload('in', 2027, "\xEF\xBB\xBF".HOLIDAY_CSV, 'Gazette notification 2027', $this->operator);

    expect($lists->map->region_code->all())->toBe(['*', 'KA', 'MH'])
        ->and($lists->every(fn ($l) => $l->status === HolidayLibraryStatus::Draft && $l->country_code === 'IN' && $l->year === 2027))->toBeTrue();
    $national = HolidayLibraryList::query()->where('region_code', '*')->sole();
    expect($national->entries->pluck('name')->all())->toBe(['Republic Day', 'Holi', 'Independence Day', 'Gandhi Jayanti'])
        ->and(libraryEntry('Independence Day')->date->toDateString())->toBe('2027-08-15')
        ->and(libraryEntry('Independence Day')->type)->toBe('public')
        ->and(libraryEntry('Holi')->type)->toBe('optional')
        ->and(libraryEntry('Karnataka Rajyotsava')->is_half_day)->toBeTrue()
        // Region names come from the file, else (India) from the state list.
        ->and(HolidayLibraryList::query()->where('region_code', 'KA')->value('region_name'))->toBe('Karnataka')
        ->and(HolidayLibraryList::query()->where('region_code', 'MH')->value('region_name'))->toBe('Maharashtra');

    $events = AuditEvent::query()->withoutTenancy()->where('module', 'attendance')->where('action', 'IMPORT')->get();
    expect($events)->toHaveCount(3)->and($events->every(fn ($e) => $e->tenant_id === null))->toBeTrue();
});

it('refuses the whole file when any row is invalid, naming each row', function () {
    $csv = implode("\n", [
        'date,name,type,half_day,region_code',
        '2027-01-26,Republic Day,public,no,',
        '01/05/2027,Labour Day,public,no,',
        '2026-12-25,Christmas,public,no,',
        '2027-03-22,Holi,festival,maybe,',
        '2027-01-26,Republic Day again,public,no,',
        '2027-04-14,,public,no,',
    ]);

    expect(fn () => $this->library->upload('IN', 2027, $csv, null, $this->operator))->toThrow(InvalidArgumentException::class, 'Nothing was uploaded.');
    try {
        $this->library->upload('IN', 2027, $csv, null, $this->operator);
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain("Row 3: date '01/05/2027' is not a date")
            ->toContain('Row 4: 2026-12-25 is not in 2027')
            ->toContain("Row 5: type 'festival' must be public or optional; half_day 'maybe' must be yes or no")
            ->toContain('Row 6: 2027-01-26 appears twice for the national list')
            ->toContain('Row 7: the name is missing');
    }
    expect(HolidayLibraryList::query()->count())->toBe(0)->and(HolidayLibraryEntry::query()->count())->toBe(0);
});

it('refuses a file without the expected header, and an empty file', function () {
    expect(fn () => $this->library->upload('IN', 2027, "day,title\n2027-01-26,Republic Day", null, $this->operator))->toThrow(InvalidArgumentException::class, 'Unknown column: day, title')
        ->and(fn () => $this->library->upload('IN', 2027, "date,name\n", null, $this->operator))->toThrow(InvalidArgumentException::class, 'The file has no holidays.')
        ->and(fn () => $this->library->upload('India', 2027, HOLIDAY_CSV, null, $this->operator))->toThrow(InvalidArgumentException::class, "'INDIA' is not a country: use its ISO 3166-1 two-letter code");
});

it('lets only platform operators upload, publish, withdraw or delete', function () {
    $list = $this->library->upload('IN', 2027, HOLIDAY_CSV, null, $this->operator)->first();

    actAsTenant($this->tenant);
    expect(fn () => $this->library->upload('IN', 2027, HOLIDAY_CSV, null, $this->hr))->toThrow(RuntimeException::class, 'Only platform operators can upload holiday lists.')
        ->and(fn () => $this->library->publish($list, $this->hr))->toThrow(RuntimeException::class, 'Only platform operators can publish holiday lists.')
        ->and(fn () => $this->library->withdraw($list, $this->hr))->toThrow(RuntimeException::class, 'Only platform operators can withdraw holiday lists.')
        ->and(fn () => $this->library->delete($list, $this->hr))->toThrow(RuntimeException::class, 'Only platform operators can delete holiday lists.');
    actAsTenant(null);

    $this->actingAs($this->hr)->get(PlatformHolidayLibraryPage::getUrl())->assertForbidden();
    $this->actingAs($this->operator)->get(PlatformHolidayLibraryPage::getUrl())->assertOk()->assertSee('India · National · 2027');
});

it('publishes, withdraws and deletes in order: a published list must be withdrawn before it is deleted', function () {
    $list = $this->library->upload('IN', 2027, HOLIDAY_CSV, null, $this->operator)->first();

    expect(fn () => $this->library->withdraw($list, $this->operator))->toThrow(RuntimeException::class, 'Only a published holiday list can be withdrawn.');
    $this->library->publish($list, $this->operator);
    expect($list->fresh()->status)->toBe(HolidayLibraryStatus::Published)
        ->and($list->fresh()->published_by)->toBe($this->operator->id)
        ->and(fn () => $this->library->publish($list->fresh(), $this->operator))->toThrow(RuntimeException::class, 'already published')
        ->and(fn () => $this->library->delete($list->fresh(), $this->operator))->toThrow(RuntimeException::class, 'Withdraw a published holiday list before deleting it.');

    $this->library->withdraw($list->fresh(), $this->operator);
    $this->library->delete($list->fresh(), $this->operator);
    expect(HolidayLibraryList::query()->whereKey($list->id)->exists())->toBeFalse()
        ->and(HolidayLibraryEntry::query()->where('holiday_library_list_id', $list->id)->exists())->toBeFalse();
});

it('offers tenants only published lists: the national holidays plus the chosen region', function () {
    $lists = $this->library->upload('IN', 2027, HOLIDAY_CSV, null, $this->operator);
    expect($this->library->countries())->toBe([]);

    $this->library->publish($lists->firstWhere('region_code', '*'), $this->operator);
    $this->library->publish($lists->firstWhere('region_code', 'MH'), $this->operator);

    expect($this->library->countries())->toBe(['IN' => 'India'])
        ->and($this->library->years('IN'))->toBe([2027 => '2027'])
        ->and($this->library->regions('IN', 2027))->toBe(['*' => 'National holidays only', 'MH' => 'Maharashtra'])
        ->and($this->library->entries('IN', 2027, 'MH')->pluck('name')->all())->toBe(['Republic Day', 'Holi', 'Maharashtra Day', 'Independence Day', 'Gandhi Jayanti'])
        ->and($this->library->entries('IN', 2027, '*')->pluck('name')->all())->toBe(['Republic Day', 'Holi', 'Independence Day', 'Gandhi Jayanti'])
        // Karnataka is still a draft.
        ->and($this->library->entries('IN', 2027, 'KA')->pluck('name')->all())->toBe(['Republic Day', 'Holi', 'Independence Day', 'Gandhi Jayanti']);
});

it('copies only the chosen holidays into a tenant calendar and skips dates the calendar already has', function () {
    publishedIndia2027();
    actAsTenant($this->tenant);
    $calendar = HolidayCalendar::create(['name' => 'Mumbai 2027', 'code' => 'MUM-27', 'year' => 2027]);
    Holiday::create(['holiday_calendar_id' => $calendar->id, 'date' => '2027-01-26', 'name' => 'Republic Day (company)', 'type' => 'public']);

    $result = $this->library->adopt($calendar, [libraryEntry('Republic Day')->id, libraryEntry('Maharashtra Day')->id, libraryEntry('Holi')->id], $this->hr);

    expect($result)->toBe(['added' => 2, 'skipped' => ['26 Jan 2027 Republic Day']])
        ->and($calendar->holidays()->pluck('name')->all())->toBe(['Republic Day (company)', 'Holi', 'Maharashtra Day'])
        ->and(Holiday::query()->where('name', 'Holi')->sole()->type)->toBe('optional')
        ->and(Holiday::query()->where('name', 'Maharashtra Day')->sole()->holiday_library_entry_id)->toBe(libraryEntry('Maharashtra Day')->id)
        ->and(Holiday::query()->where('name', 'Maharashtra Day')->sole()->tenant_id)->toBe($this->tenant->id)
        ->and(AuditEvent::query()->where('action', 'BULK_OPERATION')->where('module', 'attendance')->exists())->toBeTrue();
});

it('refuses holidays of unpublished lists, an empty choice, and a user who cannot manage attendance', function () {
    $lists = $this->library->upload('IN', 2027, HOLIDAY_CSV, null, $this->operator);
    $this->library->publish($lists->firstWhere('region_code', '*'), $this->operator);
    actAsTenant($this->tenant);
    $calendar = HolidayCalendar::create(['name' => 'Bengaluru 2027', 'code' => 'BLR-27']);

    expect(fn () => $this->library->adopt($calendar, [libraryEntry('Karnataka Rajyotsava')->id], $this->hr))->toThrow(RuntimeException::class, 'no longer offered by the holiday library')
        ->and(fn () => $this->library->adopt($calendar, [], $this->hr))->toThrow(InvalidArgumentException::class, 'Choose at least one holiday.')
        ->and(fn () => $this->library->adopt($calendar, [libraryEntry('Republic Day')->id], $this->viewer))->toThrow(RuntimeException::class, 'You cannot change this holiday calendar.')
        ->and($calendar->holidays()->count())->toBe(0);
});

it('never changes tenant copies when the library changes: a re-upload returns the list to draft and keeps unchanged dates', function () {
    publishedIndia2027();
    actAsTenant($this->tenant);
    $calendar = HolidayCalendar::create(['name' => 'National 2027', 'code' => 'NAT-27']);
    $this->library->adopt($calendar, [libraryEntry('Republic Day')->id, libraryEntry('Gandhi Jayanti')->id], $this->hr);
    $republicDay = libraryEntry('Republic Day')->id;
    actAsTenant(null);

    $this->library->upload('IN', 2027, "date,name\n2027-01-26,Republic Day\n2027-08-15,Independence Day", 'Corrected notification', $this->operator);

    $national = HolidayLibraryList::query()->where('region_code', '*')->sole();
    expect($national->status)->toBe(HolidayLibraryStatus::Draft)
        ->and($national->published_at)->toBeNull()
        ->and($national->source)->toBe('Corrected notification')
        ->and($national->entries->pluck('name')->all())->toBe(['Republic Day', 'Independence Day'])
        ->and(libraryEntry('Republic Day')->id)->toBe($republicDay)
        // Regions not in the file keep their lists.
        ->and(HolidayLibraryList::query()->where('region_code', 'MH')->value('status'))->toBe(HolidayLibraryStatus::Published);

    actAsTenant($this->tenant);
    expect($calendar->holidays()->pluck('name')->all())->toBe(['Republic Day', 'Gandhi Jayanti'])
        ->and($calendar->holidays()->pluck('holiday_library_entry_id')->all())->toBe([$republicDay, null]);
});

it('uploads, publishes and withdraws from the operators\' page', function () {
    $this->actingAs($this->operator);
    Livewire::test(PlatformHolidayLibraryPage::class)
        ->assertSee('No holiday list has been uploaded')
        ->callAction('upload', data: ['country_code' => 'IN', 'year' => 2027, 'source' => 'Gazette', 'file' => UploadedFile::fake()->createWithContent('holidays.csv', HOLIDAY_CSV)])
        ->assertNotified('Uploaded as draft: National, Karnataka, Maharashtra. Review and publish.')
        ->assertSee('India · Maharashtra · 2027')->assertSee('Maharashtra Day');
    expect(Storage::disk('local')->allFiles('holiday-library/uploads'))->toBe([]);

    $list = HolidayLibraryList::query()->where('region_code', 'MH')->sole();
    Livewire::test(PlatformHolidayLibraryPage::class)->callAction('publish', arguments: ['list' => $list->id])->assertNotified('Published');
    expect($list->fresh()->status)->toBe(HolidayLibraryStatus::Published);
    Livewire::test(PlatformHolidayLibraryPage::class)->callAction('withdraw', arguments: ['list' => $list->id])->assertNotified('Withdrawn');
    expect($list->fresh()->status)->toBe(HolidayLibraryStatus::Withdrawn);

    Livewire::test(PlatformHolidayLibraryPage::class)
        ->callAction('upload', data: ['country_code' => 'IN', 'year' => 2027, 'file' => UploadedFile::fake()->createWithContent('bad.csv', "date,name\n2026-01-01,New Year")])
        ->assertNotified('Nothing was uploaded. Row 2: 2026-01-01 is not in 2027.');
    // The template is offered inside the upload dialog only.
    Livewire::test(PlatformHolidayLibraryPage::class)->assertActionDoesNotExist('template');
});

it('shows the CSV format in the upload dialog, column by column, with a sample the upload accepts', function () {
    expect(array_keys(HolidayLibrary::FORMAT))->toBe(HolidayLibrary::COLUMNS);

    $guide = PlatformHolidayLibraryPage::csvFormat()->render();
    foreach (HolidayLibrary::COLUMNS as $column) {
        expect($guide)->toContain(">{$column}</code>");
    }
    expect($guide)->toContain('CSV format')->toContain('Example file')->toContain('2027-01-26, 26 Jan 2027 or Jan 26, 2027')->toContain('Works for any country')->toContain('>US-CA</code> California, United States')
        ->toContain(e(explode("\n", HolidayLibrary::templateCsv(now()->year + 1))[1]));

    // The sample and template parse cleanly: national, Karnataka and Maharashtra.
    expect(array_keys($this->library->parse(HolidayLibrary::templateCsv(2027), 2027, 'IN')))->toBe(['*', 'KA', 'MH']);

    $this->actingAs($this->operator);
    Livewire::test(PlatformHolidayLibraryPage::class)->mountAction('upload')->assertActionMounted('upload');
    Livewire::test(PlatformHolidayLibraryPage::class)
        ->callAction([TestAction::make('upload'), TestAction::make('downloadTemplate')])->assertFileDownloaded('holiday-library-template.csv');
});

it('adds the holidays a tenant ticks to a calendar from its holidays tab', function () {
    publishedIndia2027();
    actAsTenant($this->tenant);
    $calendar = HolidayCalendar::create(['name' => 'Mumbai 2027', 'code' => 'MUM-27', 'year' => 2027]);
    $this->actingAs($this->hr);

    Livewire::test(HolidaysRelationManager::class, ['ownerRecord' => $calendar, 'pageClass' => EditHolidayCalendar::class])
        ->assertActionVisible(TestAction::make('addFromLibrary')->table())
        ->callAction(TestAction::make('addFromLibrary')->table(), data: ['library_country' => 'IN', 'library_year' => 2027, 'library_region' => 'MH',
            'library_entries' => [libraryEntry('Republic Day')->id, libraryEntry('Maharashtra Day')->id]])
        ->assertHasNoFormErrors()
        ->assertNotified('2 holidays added')
        ->assertSee('Holiday library');

    expect($calendar->holidays()->pluck('name')->all())->toBe(['Republic Day', 'Maharashtra Day']);

    $this->actingAs($this->viewer);
    Livewire::test(HolidaysRelationManager::class, ['ownerRecord' => $calendar, 'pageClass' => EditHolidayCalendar::class])
        ->assertActionHidden(TestAction::make('addFromLibrary')->table());
});

it('creates a calendar from the library on the calendars list, and hides the action while nothing is published', function () {
    actAsTenant($this->tenant);
    $this->actingAs($this->hr);
    Livewire::test(ListHolidayCalendars::class)->assertActionHidden('createFromLibrary');

    publishedIndia2027();
    Livewire::test(ListHolidayCalendars::class)
        ->assertActionVisible('createFromLibrary')
        ->callAction('createFromLibrary', data: ['library_country' => 'IN', 'library_year' => 2027, 'library_region' => 'KA',
            'library_entries' => [libraryEntry('Republic Day')->id, libraryEntry('Karnataka Rajyotsava')->id], 'name' => 'Bengaluru 2027', 'code' => 'BLR-27'])
        ->assertHasNoFormErrors()
        ->assertNotified('2 holidays added');

    $calendar = HolidayCalendar::query()->where('code', 'BLR-27')->sole();
    expect($calendar->year)->toBe(2027)
        ->and($calendar->holidays()->pluck('name')->all())->toBe(['Republic Day', 'Karnataka Rajyotsava'])
        ->and(Holiday::query()->where('name', 'Karnataka Rajyotsava')->sole()->is_half_day)->toBeTrue();

    actAsTenant(null);
    $this->get(HolidayCalendarResource::getUrl('edit', ['record' => $calendar]))->assertOk()->assertSee('Bengaluru 2027');
});

it('takes any country: region codes with or without the country prefix, month-first dates and each country\'s type words', function () {
    $csv = implode("\n", [
        'region_code,date,name,type,region_name',
        ',"Jan 1, 2027",New Year\'s Day,federal,',
        ',2027/07/04,Independence Day,,',
        ',"November 11, 2027",Veterans Day,bank,',
        ',Dec 25 2027,Christmas Day,statutory,',
        'US-CA,2027-03-31,Cesar Chavez Day,public,California',
        'ca,26 Nov 2027,Day after Thanksgiving,floating,',
    ]);
    $lists = $this->library->upload('us', 2027, $csv, 'Federal and California calendars', $this->operator);

    expect($lists->map->region_code->all())->toBe(['*', 'CA'])
        ->and($lists->every(fn ($l) => $l->country_code === 'US'))->toBeTrue()
        ->and($lists->firstWhere('region_code', 'CA')->label())->toBe('United States · California · 2027')
        ->and(HolidayLibraryList::query()->where('region_code', '*')->sole()->entries->map(fn ($e) => [$e->date->toDateString(), $e->name, $e->type])->all())->toBe([
            ['2027-01-01', "New Year's Day", 'public'], ['2027-07-04', 'Independence Day', 'public'],
            ['2027-11-11', 'Veterans Day', 'public'], ['2027-12-25', 'Christmas Day', 'public'],
        ])
        ->and(HolidayLibraryList::query()->where('region_code', 'CA')->sole()->entries->pluck('name')->all())->toBe(['Cesar Chavez Day', 'Day after Thanksgiving'])
        ->and(libraryEntry('Day after Thanksgiving')->type)->toBe('optional');

    foreach ($lists as $list) {
        $this->library->publish($list, $this->operator);
    }
    expect($this->library->countries())->toBe(['US' => 'United States'])
        ->and($this->library->regions('US', 2027))->toBe(['*' => 'National holidays only', 'CA' => 'California']);
});

it('refuses an unknown country and any region not on the country\'s region list; region names come from the list', function () {
    expect(fn () => $this->library->upload('XX', 2027, HOLIDAY_CSV, null, $this->operator))->toThrow(InvalidArgumentException::class, "'XX' is not a country")
        ->and(fn () => $this->library->upload('EU', 2027, HOLIDAY_CSV, null, $this->operator))->toThrow(InvalidArgumentException::class, "'EU' is not a country")
        ->and(fn () => $this->library->upload('IN', 2027, "date,name,region_code\n2027-05-01,Maharashtra Day,MAH", null, $this->operator))
        ->toThrow(InvalidArgumentException::class, "Row 2: region_code 'MAH' is not on the region list for India (Platform → Regions).")
        ->and(HolidayLibraryList::query()->count())->toBe(0);

    // The prefix is optional; a region_name in an older file gives way to the list's name.
    $scotland = $this->library->upload('GB', 2027, "date,name,region_code,region_name\n2027-11-30,St Andrew's Day,GB-SCT,Alba\n2027-01-02,2nd January,SCT,", null, $this->operator)->sole();
    $goa = $this->library->upload('IN', 2027, "date,name,region_code\n2027-12-19,Goa Liberation Day,IN-GA", null, $this->operator)->sole();
    expect($scotland->label())->toBe('United Kingdom · Scotland · 2027')->and($scotland->entries)->toHaveCount(2)
        ->and($goa->region_code)->toBe('GA')->and($goa->region_name)->toBe('Goa');
});

it('offers every country on the operators\' page, not only the country packs', function () {
    $this->actingAs($this->operator);
    Livewire::test(PlatformHolidayLibraryPage::class)
        ->callAction('upload', data: ['country_code' => 'DE', 'year' => 2027, 'file' => UploadedFile::fake()->createWithContent('de.csv', "date,name,region_code,region_name\n2027-10-03,Tag der Deutschen Einheit,,\n2027-01-06,Heilige Drei Könige,DE-BY,Bayern")])
        ->assertNotified('Uploaded as draft: National, Bayern. Review and publish.')
        ->assertSee('Germany · National · 2027')->assertSee('Heilige Drei Könige');
});

it('starts the tenant on its own company\'s country when the library offers several', function () {
    publishedIndia2027();
    foreach ($this->library->upload('US', 2027, "date,name\n2027-07-04,Fourth of July", null, $this->operator) as $list) {
        $this->library->publish($list, $this->operator);
    }
    actAsTenant($this->tenant);
    expect(HolidayLibraryPicker::defaults(2027)['library_country'])->toBeNull();

    Company::factory()->create(['country_code' => 'US']);
    $defaults = HolidayLibraryPicker::defaults(2027);
    expect($this->library->countries())->toBe(['IN' => 'India', 'US' => 'United States'])
        ->and($defaults['library_country'])->toBe('US')
        ->and($defaults['library_year'])->toBe(2027)
        // The United States has only a national list, so it is chosen, with its public holidays ticked.
        ->and($defaults['library_region'])->toBe('*')
        ->and($defaults['library_entries'])->toBe([libraryEntry('Fourth of July')->id]);
});

it('starts the tenant on the region its locations are in, and names those locations beside their regions', function () {
    publishedIndia2027();
    actAsTenant($this->tenant);
    $company = Company::factory()->create(['country_code' => 'IN']);
    // Matched by code with or without the prefix, or by name in any case.
    Location::factory()->create(['company_id' => $company->id, 'name' => 'Mumbai HQ', 'state_code' => 'IN-MH']);
    Location::factory()->create(['company_id' => $company->id, 'name' => 'Pune Office', 'state_code' => 'maharashtra']);
    Location::factory()->create(['company_id' => $company->id, 'name' => 'Dubai Office', 'state_code' => 'DU', 'country_code' => 'AE']);

    $defaults = HolidayLibraryPicker::defaults(2027);
    expect($this->library->locationRegions('IN'))->toBe(['MH' => ['Mumbai HQ', 'Pune Office']])
        ->and($defaults['library_region'])->toBe('MH')
        ->and(HolidayLibraryEntry::query()->whereKey($defaults['library_entries'])->orderBy('date')->pluck('name')->all())
        ->toBe(['Republic Day', 'Maharashtra Day', 'Independence Day', 'Gandhi Jayanti']);

    // Locations in two regions: the tenant chooses, with each region naming its locations.
    Location::factory()->create(['company_id' => $company->id, 'name' => 'Bengaluru Office', 'state_code' => 'KA']);
    expect(HolidayLibraryPicker::defaults(2027)['library_region'])->toBeNull()
        ->and(HolidayLibraryPicker::regionOptions('IN', 2027))->toBe([
            '*' => 'National holidays only',
            'KA' => 'Karnataka · your locations: Bengaluru Office',
            'MH' => 'Maharashtra · your locations: Mumbai HQ, Pune Office',
        ]);
});
