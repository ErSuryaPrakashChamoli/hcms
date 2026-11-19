<?php

namespace App\Domain\Organisation\Support;

use App\Domain\Attendance\Services\CountryRegions;

/**
 * Which parts of an address a country asks for, beyond the country itself.
 *
 * - State: offered whenever the platform's region list (ISO 3166-2, Admin → Regions) has regions for the country.
 *   It is required where the state or province is a payroll or labour-law jurisdiction and the list's top level is
 *   that jurisdiction: professional tax, LWF and shops & establishments in India; state income tax and unemployment
 *   insurance in the US; provincial tax in Canada; state payroll tax in Australia and Mexico; church tax and public
 *   holidays in Germany; cantonal tax in Switzerland; regional income tax in Spain and Italy; state PAYE in Nigeria;
 *   regional minimum wages in Japan, China, Thailand, the Philippines and Russia; state holidays and rest days in
 *   Malaysia; the emirate in the UAE; Brazil and Korea, whose postal addresses require it. Indonesia is left out on
 *   purpose: the list's top level there is island groups, not provinces.
 * - City: asked for everywhere except city-states, where the country is the city. Required where the state is.
 *
 * Owner preference "decide by best practice": the reasoning above is the record of the decision.
 */
final class AddressRules
{
    /** Countries whose state is required (see above). */
    public const STATE_REQUIRED = ['AE', 'AU', 'BR', 'CA', 'CH', 'CN', 'DE', 'ES', 'IN', 'IT', 'JP', 'KR', 'MX', 'MY', 'NG', 'PH', 'PK', 'RU', 'TH', 'US'];

    /** City-states: no state and no city is asked for. */
    public const CITY_STATES = ['GI', 'HK', 'MC', 'MO', 'SG', 'VA'];

    /** What the state is called where it is not "State". */
    private const STATE_LABELS = [
        'AE' => 'Emirate', 'CA' => 'Province', 'CH' => 'Canton', 'CN' => 'Province', 'ES' => 'Autonomous community', 'FR' => 'Region',
        'GB' => 'Nation', 'IE' => 'Province', 'IT' => 'Region', 'JP' => 'Prefecture', 'KR' => 'Province', 'NL' => 'Province',
        'NZ' => 'Region', 'PH' => 'Region', 'PK' => 'Province', 'RU' => 'Region', 'TH' => 'Province', 'ZA' => 'Province',
    ];

    /** @return array<string, string> the country's active regions: code => name, by name */
    public static function states(?string $country): array
    {
        $country = strtoupper((string) $country);
        if ($country === '' || in_array($country, self::CITY_STATES, true)) {
            return [];
        }

        return once(fn () => app(CountryRegions::class)->active($country));
    }

    public static function hasState(?string $country): bool
    {
        return self::states($country) !== [];
    }

    public static function stateRequired(?string $country): bool
    {
        return in_array(strtoupper((string) $country), self::STATE_REQUIRED, true) && self::hasState($country);
    }

    public static function stateLabel(?string $country): string
    {
        return self::STATE_LABELS[strtoupper((string) $country)] ?? 'State';
    }

    public static function hasCity(?string $country): bool
    {
        return filled($country) && ! in_array(strtoupper((string) $country), self::CITY_STATES, true);
    }

    public static function cityRequired(?string $country): bool
    {
        return self::hasCity($country) && self::stateRequired($country);
    }
}
