<?php

namespace App\Filament\Pages;

use App\Domain\Attendance\Models\CountryRegion;
use App\Domain\Attendance\Models\HolidayLibraryList;
use App\Domain\Attendance\Services\CountryRegions;
use App\Support\Geography\Countries;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use RuntimeException;
use UnitEnum;

/**
 * The fixed region list per country (states, provinces, emirates…), pre-filled from ISO 3166-2. Holiday library
 * uploads may only use these codes, and tenants' locations are matched to them.
 */
class PlatformRegionsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'Regions';

    protected static ?string $title = 'Regions';

    protected static ?string $slug = 'platform-regions';

    protected static ?int $navigationSort = 11;

    protected string $view = 'filament.pages.platform-regions';

    public string $country = 'IN';

    /** @var array{0: string, 1: Collection<int, CountryRegion>}|null per-request memo: visible() runs several times a render */
    private ?array $memo = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->isPlatformAdmin() ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function updatedCountry(): void
    {
        $this->country = Countries::exists($this->country) ? strtoupper($this->country) : 'IN';
    }

    /** @return array<string, string> */
    public function countries(): array
    {
        return Countries::all();
    }

    /** @return Collection<int, CountryRegion> */
    public function regions(): Collection
    {
        if ($this->memo === null || $this->memo[0] !== $this->country) {
            $this->memo = [$this->country, app(CountryRegions::class)->forCountry($this->country)];
        }

        return $this->memo[1];
    }

    /** @return array<string, int> region code => holiday lists for it (any year or status) */
    public function listCounts(): array
    {
        return HolidayLibraryList::query()->where('country_code', $this->country)->where('region_code', '!=', HolidayLibraryList::NATIONAL)
            ->selectRaw('region_code, count(*) as lists')->groupBy('region_code')->toBase()->get()
            ->mapWithKeys(fn ($row) => [$row->region_code => (int) $row->lists])->all();
    }

    public function missingStandard(): int
    {
        $standard = CountryRegions::standard()[$this->country] ?? [];

        return count(array_diff_key($standard, $this->regions()->keyBy('code')->all()));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addRegion')->label('Add region')->icon(Heroicon::OutlinedPlus)
                ->modalHeading(fn () => 'Add a region to '.Countries::name($this->country))
                ->modalDescription('For a region the standard list lacks. Use its official code where it has one; the code cannot be changed later.')
                ->schema([
                    TextInput::make('code')->label('Code (without the country prefix)')->required()->maxLength(19)->placeholder('MH'),
                    TextInput::make('name')->required()->maxLength(255)->placeholder('Maharashtra'),
                ])
                ->action(fn (array $data) => $this->attempt(function () use ($data) {
                    $region = app(CountryRegions::class)->add($this->country, (string) $data['code'], (string) $data['name'], auth()->user());

                    return "Added {$region->isoCode()} {$region->name}";
                })),
            Action::make('syncStandard')->label('Add missing ISO regions')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                ->visible(fn () => $this->missingStandard() > 0)
                ->requiresConfirmation()
                ->modalDescription('Adds the standard ISO 3166-2 regions this country\'s list does not have. Nothing is renamed, restored or removed.')
                ->action(fn () => $this->attempt(function () {
                    $added = app(CountryRegions::class)->syncStandard($this->country, auth()->user());

                    return $added === 1 ? '1 region added' : "{$added} regions added";
                })),
        ];
    }

    public function renameAction(): Action
    {
        return Action::make('rename')->label('Rename')->color('gray')->size('sm')
            ->fillForm(fn (array $arguments) => ['name' => $this->find($arguments)->name])
            ->modalDescription('The new name shows wherever the region is offered, holiday lists included. The code stays.')
            ->schema([TextInput::make('name')->required()->maxLength(255)])
            ->action(fn (array $data, array $arguments) => $this->attempt(function () use ($data, $arguments) {
                app(CountryRegions::class)->rename($this->find($arguments), (string) $data['name'], auth()->user());

                return 'Renamed';
            }));
    }

    public function retireAction(): Action
    {
        return Action::make('retire')->label('Retire')->color('gray')->size('sm')->requiresConfirmation()
            ->modalDescription('New holiday uploads can no longer use this region. Lists already uploaded for it stay as they are.')
            ->action(fn (array $arguments) => $this->attempt(function () use ($arguments) {
                app(CountryRegions::class)->retire($this->find($arguments), auth()->user());

                return 'Retired';
            }));
    }

    public function restoreAction(): Action
    {
        return Action::make('restore')->label('Restore')->color('gray')->size('sm')
            ->action(fn (array $arguments) => $this->attempt(function () use ($arguments) {
                app(CountryRegions::class)->restore($this->find($arguments), auth()->user());

                return 'Restored';
            }));
    }

    private function find(array $arguments): CountryRegion
    {
        return CountryRegion::query()->findOrFail((int) ($arguments['region'] ?? 0));
    }

    /** @param  Closure(): string  $work */
    private function attempt(Closure $work): void
    {
        try {
            $title = $work();
            $this->memo = null;
            Notification::make()->success()->title($title)->send();
        } catch (RuntimeException|\InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }
}
