<?php

namespace App\Support\Geography;

use NumberFormatter;
use ResourceBundle;
use Throwable;

/**
 * Every currency a country uses today, by its ISO 4217 code, with its English name, and each country's own currency:
 * all from ICU (the intl extension), never a list kept here. For locale fields such as a company's currency. The
 * billing catalogue (which currencies are sold in) is App\Support\Money\Currency, a separate, controlled list.
 */
final class Currencies
{
    /** @var array<string, string>|null ICU data is fixed for the process */
    private static ?array $all = null;

    /** @return array<string, string> code => name, sorted by code */
    public static function all(): array
    {
        if (self::$all === null) {
            $names = ResourceBundle::create('en', 'ICUDATA-curr')['Currencies'];
            $all = [];
            foreach (array_keys(Countries::all()) as $country) {
                $code = self::forCountry($country);
                $name = $code === null ? null : ($names[$code][1] ?? null);
                if ($name !== null) {
                    $all[$code] = (string) $name;
                }
            }
            ksort($all);
            self::$all = $all;
        }

        return self::$all;
    }

    /** The currency the country uses (INR for IN); null when it has none (Antarctica) or the code is not a country. */
    public static function forCountry(?string $country): ?string
    {
        $country = strtoupper((string) $country);
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            return null;
        }
        try {
            $code = (new NumberFormatter("en_{$country}", NumberFormatter::CURRENCY))->getTextAttribute(NumberFormatter::CURRENCY_CODE);
        } catch (Throwable) {
            return null;
        }

        return is_string($code) && preg_match('/^[A-Z]{3}$/', $code) === 1 && $code !== 'XXX' ? $code : null;
    }

    /**
     * code => "INR · Indian Rupee", for selects. A saved code that is no longer in use stays listed so it still shows.
     *
     * @return array<string, string>
     */
    public static function options(?string $saved = null): array
    {
        $options = [];
        foreach (self::all() as $code => $name) {
            $options[$code] = "{$code} · {$name}";
        }
        if (filled($saved) && ! isset($options[$saved])) {
            $options[$saved] = $saved;
        }

        return $options;
    }
}
