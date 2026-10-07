<?php

namespace App\Domain\Tax\Jurisdictions\India;

/**
 * SaaS.7 India GST: ISO 3166-2:IN subdivisions with their GST state codes, and whether the territory is a union
 * territory without a legislature (where UTGST replaces SGST). Reference data for the India jurisdiction only
 * [pending tax review]. Former ISO codes are accepted as aliases of the current ones.
 */
final class GstStates
{
    /** @var array<string, array{0: string, 1: string, 2: bool}> subdivision => [GST code, name, union territory without legislature] */
    private const STATES = [
        'IN-AN' => ['35', 'Andaman and Nicobar Islands', true], 'IN-AP' => ['37', 'Andhra Pradesh', false], 'IN-AR' => ['12', 'Arunachal Pradesh', false],
        'IN-AS' => ['18', 'Assam', false], 'IN-BR' => ['10', 'Bihar', false], 'IN-CH' => ['04', 'Chandigarh', true],
        'IN-CG' => ['22', 'Chhattisgarh', false], 'IN-DH' => ['26', 'Dadra and Nagar Haveli and Daman and Diu', true], 'IN-DL' => ['07', 'Delhi', false],
        'IN-GA' => ['30', 'Goa', false], 'IN-GJ' => ['24', 'Gujarat', false], 'IN-HP' => ['02', 'Himachal Pradesh', false],
        'IN-HR' => ['06', 'Haryana', false], 'IN-JH' => ['20', 'Jharkhand', false], 'IN-JK' => ['01', 'Jammu and Kashmir', false],
        'IN-KA' => ['29', 'Karnataka', false], 'IN-KL' => ['32', 'Kerala', false], 'IN-LA' => ['38', 'Ladakh', true],
        'IN-LD' => ['31', 'Lakshadweep', true], 'IN-MH' => ['27', 'Maharashtra', false], 'IN-ML' => ['17', 'Meghalaya', false],
        'IN-MN' => ['14', 'Manipur', false], 'IN-MP' => ['23', 'Madhya Pradesh', false], 'IN-MZ' => ['15', 'Mizoram', false],
        'IN-NL' => ['13', 'Nagaland', false], 'IN-OD' => ['21', 'Odisha', false], 'IN-PB' => ['03', 'Punjab', false],
        'IN-PY' => ['34', 'Puducherry', false], 'IN-RJ' => ['08', 'Rajasthan', false], 'IN-SK' => ['11', 'Sikkim', false],
        'IN-TG' => ['36', 'Telangana', false], 'IN-TN' => ['33', 'Tamil Nadu', false], 'IN-TR' => ['16', 'Tripura', false],
        'IN-UK' => ['05', 'Uttarakhand', false], 'IN-UP' => ['09', 'Uttar Pradesh', false], 'IN-WB' => ['19', 'West Bengal', false],
    ];

    private const ALIASES = ['IN-CT' => 'IN-CG', 'IN-OR' => 'IN-OD', 'IN-UT' => 'IN-UK', 'IN-TS' => 'IN-TG'];

    public static function canonical(?string $subdivision): ?string
    {
        if ($subdivision === null) {
            return null;
        }
        $subdivision = self::ALIASES[$subdivision] ?? $subdivision;

        return isset(self::STATES[$subdivision]) ? $subdivision : null;
    }

    public static function code(?string $subdivision): ?string
    {
        $canonical = self::canonical($subdivision);

        return $canonical === null ? null : self::STATES[$canonical][0];
    }

    public static function name(?string $subdivision): ?string
    {
        $canonical = self::canonical($subdivision);

        return $canonical === null ? null : self::STATES[$canonical][1];
    }

    public static function isUnionTerritoryWithoutLegislature(?string $subdivision): bool
    {
        $canonical = self::canonical($subdivision);

        return $canonical !== null && self::STATES[$canonical][2];
    }

    public static function isKnownCode(string $code): bool
    {
        foreach (self::STATES as [$gst]) {
            if ($gst === $code) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> subdivision => "Name (code)" */
    public static function options(): array
    {
        $options = [];
        foreach (self::STATES as $subdivision => [$code, $name]) {
            $options[$subdivision] = "{$name} ({$code})";
        }
        asort($options);

        return $options;
    }
}
