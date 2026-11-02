<?php

namespace App\Filament\Support;

use App\Support\Geography\Countries;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Exception;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;

/**
 * A time zone chosen in two steps: the country, then one of that country's IANA zones (from the time-zone database,
 * never a list kept here). The country only narrows the list and is not saved: an existing record's country is read
 * back from its zone, and a new one starts at the organisation's country.
 */
final class TimezoneFields
{
    /** @return list<Select> */
    public static function make(string $field = 'timezone'): array
    {
        $country = $field.'_country';

        return [
            Select::make($country)->label('Country')->options(fn () => Countries::all())->searchable()->live()->dehydrated(false)
                ->placeholder('Choose a country')
                ->afterStateHydrated(fn (Select $component, Get $get) => $component->state(self::countryOf($get($field)) ?? $component->getState() ?? self::tenantCountry()))
                ->afterStateUpdated(fn (Set $set, ?string $state) => $set($field, self::onlyZone($state))),
            Select::make($field)->label('Time zone')->searchable()->rule('timezone')
                ->options(fn (Get $get, ?Model $record) => self::options($get($country), $record?->getAttribute($field)))
                ->default(fn () => self::onlyZone(self::tenantCountry()))
                ->placeholder(fn (Get $get) => blank($get($country)) ? 'Choose a country first' : 'Choose a time zone'),
        ];
    }

    /** @return list<string> the country's zones; none when no country is chosen */
    public static function zones(?string $country): array
    {
        return Countries::exists($country) ? DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, strtoupper($country)) : [];
    }

    /** The country a zone belongs to; null for zones without one (UTC) and for values that are not zones. */
    public static function countryOf(?string $zone): ?string
    {
        if (blank($zone)) {
            return null;
        }
        try {
            $code = (new DateTimeZone($zone))->getLocation()['country_code'] ?? null;
        } catch (Exception) {
            return null;
        }

        return Countries::exists($code) ? $code : null;
    }

    /**
     * zone => "Asia/Kolkata · UTC+05:30". The record's saved zone stays listed when it is outside the country's list
     * (UTC, an older entry) so it still shows; nothing else outside the country can be chosen.
     *
     * @return array<string, string>
     */
    private static function options(?string $country, ?string $saved): array
    {
        $zones = self::zones($country);
        if (filled($saved) && ! in_array($saved, $zones, true)) {
            $zones[] = $saved;
        }

        return collect($zones)->mapWithKeys(fn (string $zone) => [$zone => self::label($zone)])->all();
    }

    private static function label(string $zone): string
    {
        try {
            return $zone.' · UTC'.CarbonImmutable::now($zone)->format('P');
        } catch (Exception) {
            return $zone;
        }
    }

    /** The zone to fill in when the country has only one. */
    private static function onlyZone(?string $country): ?string
    {
        $zones = self::zones($country);

        return count($zones) === 1 ? $zones[0] : null;
    }

    private static function tenantCountry(): ?string
    {
        return app(TenantContext::class)->current()?->country_code;
    }
}
