<?php

namespace App\Support\Geography;

use Collator;
use ResourceBundle;

/**
 * Every country, by its ISO 3166-1 alpha-2 code, with its English name from ICU (the intl extension), for lists that
 * must not stop at the country packs (e.g. the holiday library).
 */
final class Countries
{
    /** ICU region codes that are groupings, pseudo-locales or only exceptionally reserved in ISO 3166-1. Kosovo (XK) stays. */
    private const NOT_COUNTRIES = ['AC', 'CP', 'CQ', 'DG', 'EA', 'EU', 'EZ', 'IC', 'QO', 'TA', 'UN', 'XA', 'XB', 'ZZ'];

    /** @var array<string, string>|null ICU data is fixed for the process */
    private static ?array $all = null;

    /** @return array<string, string> code => name, sorted by name */
    public static function all(): array
    {
        if (self::$all === null) {
            $names = [];
            foreach (ResourceBundle::create('en', 'ICUDATA-region')['Countries'] as $code => $name) {
                if (preg_match('/^[A-Z]{2}$/', (string) $code) === 1 && ! in_array($code, self::NOT_COUNTRIES, true)) {
                    $names[$code] = (string) $name;
                }
            }
            (new Collator('en'))->asort($names);
            self::$all = $names;
        }

        return self::$all;
    }

    public static function exists(?string $code): bool
    {
        return isset(self::all()[strtoupper((string) $code)]);
    }

    /**
     * Names first (2026-11-26): the ISO code of a country given by its code (IN) or its English name (India, any case),
     * or null when it is neither.
     */
    public static function codeOf(?string $nameOrCode): ?string
    {
        $value = trim((string) $nameOrCode);
        if ($value === '') {
            return null;
        }
        if (self::exists($value)) {
            return strtoupper($value);
        }
        $wanted = mb_strtolower(preg_replace('/\s+/u', ' ', $value));
        foreach (self::all() as $code => $name) {
            if (mb_strtolower($name) === $wanted) {
                return $code;
            }
        }

        return null;
    }

    /** The country's name, or the code itself when it is not a country. */
    public static function name(?string $code): string
    {
        $code = strtoupper((string) $code);

        return self::all()[$code] ?? $code;
    }
}
