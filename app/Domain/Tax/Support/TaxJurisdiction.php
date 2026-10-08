<?php

namespace App\Domain\Tax\Support;

use InvalidArgumentException;

/**
 * SaaS.7: a tax jurisdiction: an ISO 3166-1 country and, where relevant, an ISO 3166-2 subdivision (state, province)
 * and a local tax jurisdiction inside it (county, city or district, as Markedge's rate source codes it; US sales tax).
 */
final readonly class TaxJurisdiction
{
    public function __construct(public string $country, public ?string $subdivision = null, public ?string $locality = null)
    {
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw new InvalidArgumentException("{$country} is not an ISO 3166-1 country code.");
        }
        if ($subdivision !== null && preg_match('/^'.$country.'-[A-Z0-9]{1,3}$/', $subdivision) !== 1) {
            throw new InvalidArgumentException("{$subdivision} is not an ISO 3166-2 subdivision of {$country}.");
        }
        if ($locality !== null && ($subdivision === null || preg_match(self::LOCALITY, $locality) !== 1)) {
            throw new InvalidArgumentException("{$locality} is not a local tax jurisdiction code (2 to 40 capital letters, digits, dot, dash, colon or underscore, inside a subdivision).");
        }
    }

    public const LOCALITY = '/^[A-Z0-9][A-Z0-9._:-]{1,39}$/';

    public function label(): string
    {
        return ($this->subdivision ?? $this->country).($this->locality !== null ? " / {$this->locality}" : '');
    }

    /** @return array{country: string, subdivision: ?string, locality: ?string} */
    public function toArray(): array
    {
        return ['country' => $this->country, 'subdivision' => $this->subdivision, 'locality' => $this->locality];
    }
}
