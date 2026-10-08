<?php

namespace App\Domain\Tax\Jurisdictions\UnitedStates;

/**
 * SaaS.7 configuration: the US states and the District of Columbia (ISO 3166-2:US), the jurisdictions a US sales-tax
 * rule is scoped to. Reference data for selection and validation only: no rate or taxability lives here.
 */
final class UsStates
{
    private const STATES = ['US-AL' => 'Alabama', 'US-AK' => 'Alaska', 'US-AZ' => 'Arizona', 'US-AR' => 'Arkansas', 'US-CA' => 'California', 'US-CO' => 'Colorado',
        'US-CT' => 'Connecticut', 'US-DE' => 'Delaware', 'US-DC' => 'District of Columbia', 'US-FL' => 'Florida', 'US-GA' => 'Georgia', 'US-HI' => 'Hawaii',
        'US-ID' => 'Idaho', 'US-IL' => 'Illinois', 'US-IN' => 'Indiana', 'US-IA' => 'Iowa', 'US-KS' => 'Kansas', 'US-KY' => 'Kentucky', 'US-LA' => 'Louisiana',
        'US-ME' => 'Maine', 'US-MD' => 'Maryland', 'US-MA' => 'Massachusetts', 'US-MI' => 'Michigan', 'US-MN' => 'Minnesota', 'US-MS' => 'Mississippi',
        'US-MO' => 'Missouri', 'US-MT' => 'Montana', 'US-NE' => 'Nebraska', 'US-NV' => 'Nevada', 'US-NH' => 'New Hampshire', 'US-NJ' => 'New Jersey',
        'US-NM' => 'New Mexico', 'US-NY' => 'New York', 'US-NC' => 'North Carolina', 'US-ND' => 'North Dakota', 'US-OH' => 'Ohio', 'US-OK' => 'Oklahoma',
        'US-OR' => 'Oregon', 'US-PA' => 'Pennsylvania', 'US-RI' => 'Rhode Island', 'US-SC' => 'South Carolina', 'US-SD' => 'South Dakota', 'US-TN' => 'Tennessee',
        'US-TX' => 'Texas', 'US-UT' => 'Utah', 'US-VT' => 'Vermont', 'US-VA' => 'Virginia', 'US-WA' => 'Washington', 'US-WV' => 'West Virginia',
        'US-WI' => 'Wisconsin', 'US-WY' => 'Wyoming'];

    /** @return array<string, string> */
    public static function options(): array
    {
        return self::STATES;
    }

    public static function name(?string $code): ?string
    {
        return self::STATES[$code] ?? null;
    }
}
