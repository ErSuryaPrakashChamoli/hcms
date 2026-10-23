<?php

namespace App\Filament\Support;

use App\Domain\Attendance\Models\HolidayLibraryEntry;
use App\Domain\Attendance\Models\HolidayLibraryList;
use App\Domain\Attendance\Services\HolidayLibrary;
use App\Domain\Organisation\Models\Company;
use App\Support\Geography\Countries;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;

/**
 * The tenant's side of the holiday library: choose a country, year and region from the platform's published lists,
 * then tick the holidays the organisation observes. HolidayLibrary::adopt copies them into a calendar.
 */
final class HolidayLibraryPicker
{
    /**
     * @param  bool  $suggestCalendar  fill an empty calendar name and code from the region and year
     * @return list<Component>
     */
    public static function fields(bool $suggestCalendar = false): array
    {
        $library = app(HolidayLibrary::class);

        return [
            Grid::make(3)->schema([
                Select::make('library_country')->label('Country')->options(fn () => $library->countries())->required()->live()
                    ->afterStateUpdated(function (Set $set) {
                        $set('library_year', null);
                        $set('library_region', null);
                        $set('library_entries', []);
                    }),
                Select::make('library_year')->label('Year')->options(fn (Get $get) => $library->years($get('library_country')))->required()->live()
                    ->afterStateUpdated(function (Set $set) {
                        $set('library_region', null);
                        $set('library_entries', []);
                    }),
                Select::make('library_region')->label('Region (state, province…)')->options(fn (Get $get) => self::regionOptions($get('library_country'), (int) $get('library_year')))->required()->live()
                    ->helperText('National holidays are always listed; a region adds its own. Regions where you have locations name them.')
                    ->afterStateUpdated(function (Get $get, Set $set) use ($suggestCalendar) {
                        $set('library_entries', self::publicIds($get('library_country'), (int) $get('library_year'), $get('library_region')));
                        if ($suggestCalendar && blank($get('name')) && filled($get('library_region'))) {
                            // The country stays in the code: CA alone could be California or Canada.
                            $national = $get('library_region') === HolidayLibraryList::NATIONAL;
                            $label = $national ? Countries::name($get('library_country')) : (app(HolidayLibrary::class)->regions($get('library_country'), (int) $get('library_year'))[$get('library_region')] ?? $get('library_region'));
                            $set('name', $label.' '.$get('library_year'));
                            $set('code', strtoupper($get('library_country').($national ? '' : '-'.$get('library_region'))).'-'.$get('library_year'));
                        }
                    }),
            ]),
            CheckboxList::make('library_entries')->label('Holidays your organisation observes')
                ->options(fn (Get $get) => self::entries($get)->mapWithKeys(fn (HolidayLibraryEntry $e) => [$e->id => HolidayLibrary::optionLabel($e)])->all())
                ->descriptions(fn (Get $get) => self::entries($get)->mapWithKeys(fn (HolidayLibraryEntry $e) => [$e->id => HolidayLibrary::optionDescription($e)])->all())
                ->bulkToggleable()->columns(2)->required()
                ->visible(fn (Get $get) => filled($get('library_region')))
                ->helperText('Public holidays start ticked. Tick the optional ones your organisation also gives and untick any it does not. Dates already in the calendar are skipped.'),
        ];
    }

    /**
     * Initial state: the only country offered, else the first of the organisation's company countries that is offered;
     * the preferred year when the library has it (else the newest); the region all the organisation's locations in that
     * country are in, else the only region.
     *
     * @return array<string, mixed>
     */
    public static function defaults(?int $preferredYear = null): array
    {
        $library = app(HolidayLibrary::class);
        $countries = $library->countries();
        $country = count($countries) === 1 ? array_key_first($countries)
            : Company::query()->whereNotNull('country_code')->orderBy('id')->pluck('country_code')->map(fn (string $c) => strtoupper($c))->first(fn (string $c) => isset($countries[$c]));
        $years = $library->years($country);
        $year = $preferredYear !== null && isset($years[$preferredYear]) ? $preferredYear : array_key_first($years);
        $regions = $year !== null ? $library->regions($country, $year) : [];
        // The region the organisation's locations are in, when they are all in one region the library offers.
        $mine = array_keys(array_diff_key(array_intersect_key($regions, $library->locationRegions($country)), [HolidayLibraryList::NATIONAL => true]));
        $region = count($mine) === 1 ? $mine[0] : (count($regions) === 1 ? array_key_first($regions) : null);

        return ['library_country' => $country, 'library_year' => $year, 'library_region' => $region,
            'library_entries' => $region !== null ? self::publicIds($country, $year, $region) : []];
    }

    /** Whether the library offers anything to pick from. */
    public static function offered(): bool
    {
        return HolidayLibraryList::query()->published()->exists();
    }

    /** Run an adoption and report it: added, and the dates the calendar already had. */
    public static function run(Closure $work): void
    {
        try {
            /** @var array{added: int, skipped: list<string>} $result */
            $result = $work();
        } catch (RuntimeException|InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        $title = $result['added'] === 1 ? '1 holiday added' : "{$result['added']} holidays added";
        Notification::make()->success()->title($title)
            ->body($result['skipped'] !== [] ? 'Already in the calendar, so skipped: '.implode(', ', $result['skipped']).'.' : null)
            ->send();
    }

    /** @return array<string, string> the library's regions, each naming the organisation's locations in it */
    public static function regionOptions(?string $country, ?int $year): array
    {
        $library = app(HolidayLibrary::class);
        $locations = $library->locationRegions($country);

        return collect($library->regions($country, $year))
            ->map(fn (string $label, string $code) => isset($locations[$code]) ? "{$label} · your locations: ".implode(', ', $locations[$code]) : $label)->all();
    }

    /** @return list<int> */
    private static function publicIds(?string $country, ?int $year, ?string $region): array
    {
        return app(HolidayLibrary::class)->entries($country, $year, $region)->where('type', 'public')->pluck('id')->values()->all();
    }

    /** @return Collection<int, HolidayLibraryEntry> */
    private static function entries(Get $get): Collection
    {
        return app(HolidayLibrary::class)->entries($get('library_country'), (int) $get('library_year') ?: null, $get('library_region'));
    }
}
