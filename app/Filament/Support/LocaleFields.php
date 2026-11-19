<?php

namespace App\Filament\Support;

use App\Domain\Organisation\Enums\GeoPlaceKind;
use App\Domain\Organisation\Models\GeoPlace;
use App\Domain\Organisation\Models\Location;
use App\Domain\Organisation\Support\AddressRules;
use App\Support\Geography\Countries;
use App\Support\Geography\Currencies;
use App\Support\Tenancy\TenantContext;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;

/**
 * A record's country, state, city, time zone and currency, each a choice where a list exists:
 * - the country from ICU's list;
 * - the state from the platform's region list for that country, shown and required as AddressRules says;
 * - the city typed, with the organisation's own cities (Organisation → Places, its branches) suggested, since there is
 *   no world list of cities to choose from; shown and required as AddressRules says;
 * - the time zone from the country's IANA zones; the currency from every currency in use.
 * Choosing a country fills in its time zone (when it has one zone, or a pack zone) and currency, and clears the state
 * and city; each stays changeable.
 */
final class LocaleFields
{
    /** @return list<Select|TextInput> country_code, state_code, city, timezone, currency */
    public static function make(): array
    {
        return [
            Select::make('country_code')->label('Country')->options(fn () => Countries::all())->searchable()->required()->live()
                ->default(fn () => self::tenantCountry())
                ->afterStateUpdated(function (Set $set, ?string $state): void {
                    $set('state_code', null);
                    $set('city', null);
                    $set('timezone', TimezoneFields::defaultZone($state));
                    $set('currency', Currencies::forCountry($state));
                }),
            Select::make('state_code')->label(fn (Get $get) => AddressRules::stateLabel($get('country_code')))->searchable()->live()
                ->options(fn (Get $get, ?Model $record) => self::stateOptions($get('country_code'), $record))
                ->placeholder('Choose one')
                ->visible(fn (Get $get) => AddressRules::hasState($get('country_code')))
                ->required(fn (Get $get) => AddressRules::stateRequired($get('country_code'))),
            TextInput::make('city')->maxLength(120)
                ->datalist(fn (Get $get) => self::citySuggestions($get('country_code'), $get('state_code')))
                ->visible(fn (Get $get) => AddressRules::hasCity($get('country_code')))
                ->required(fn (Get $get) => AddressRules::cityRequired($get('country_code'))),
            TimezoneFields::zoneSelect('timezone', 'country_code')
                ->default(fn () => TimezoneFields::defaultZone(self::tenantCountry()))
                ->afterStateHydrated(fn (Select $component, Get $get) => self::fillBlank($component, TimezoneFields::defaultZone($get('country_code')))),
            Select::make('currency')->searchable()->placeholder('Choose a currency')
                ->options(fn (?Model $record) => Currencies::options($record?->getAttribute('currency')))
                ->default(fn () => Currencies::forCountry(self::tenantCountry()))
                ->afterStateHydrated(fn (Select $component, Get $get) => self::fillBlank($component, Currencies::forCountry($get('country_code')))),
        ];
    }

    /** A record saved without a time zone or currency opens with its country's, for the person to confirm on saving. Saved values are never replaced. */
    private static function fillBlank(Select $component, ?string $value): void
    {
        if (blank($component->getState()) && filled($value)) {
            $component->state($value);
        }
    }

    /**
     * code => "Maharashtra (MH)". A saved state no longer on the list (retired) stays listed for its own country so it
     * still shows.
     *
     * @return array<string, string>
     */
    private static function stateOptions(?string $country, ?Model $record): array
    {
        $options = [];
        foreach (AddressRules::states($country) as $code => $name) {
            $options[$code] = "{$name} ({$code})";
        }
        $saved = $record?->getAttribute('state_code');
        if (filled($saved) && ! isset($options[$saved]) && strtoupper((string) $record->getAttribute('country_code')) === strtoupper((string) $country)) {
            $options[$saved] = (string) $saved;
        }

        return $options;
    }

    /** @return list<string> cities on the organisation's own list and at its branches, in the country (and state, when chosen) */
    private static function citySuggestions(?string $country, ?string $state): array
    {
        if (! AddressRules::hasCity($country)) {
            return [];
        }
        $country = strtoupper((string) $country);
        $stateNames = filled($state) ? array_filter([$state, AddressRules::states($country)[$state] ?? null]) : [];

        $places = GeoPlace::query()->where('kind', GeoPlaceKind::City->value)->where('country_code', $country)
            ->when(filled($state), fn ($q) => $q->whereIn('state_id', GeoPlace::query()->select('id')
                ->where('kind', GeoPlaceKind::State->value)->where('region_code', $state)))
            ->pluck('name');
        $branches = Location::query()->where('country_code', $country)->whereNotNull('city')->where('city', '!=', '')
            ->when($stateNames !== [], fn ($q) => $q->whereIn('state_code', $stateNames))
            ->distinct()->pluck('city');

        // One spelling per city, whatever its case.
        return $places->merge($branches)->map(fn ($city) => trim((string) $city))->filter()->keyBy(fn ($city) => mb_strtolower($city))
            ->sort()->take(200)->values()->all();
    }

    private static function tenantCountry(): string
    {
        return app(TenantContext::class)->current()?->country_code ?: 'IN';
    }
}
