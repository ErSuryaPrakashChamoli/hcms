<?php

namespace App\Domain\Tax\Support;

use InvalidArgumentException;

/** SaaS.7: a tax jurisdiction: an ISO 3166-1 country and, where relevant, an ISO 3166-2 subdivision (state, province). */
final readonly class TaxJurisdiction
{
    public function __construct(public string $country, public ?string $subdivision = null)
    {
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw new InvalidArgumentException("{$country} is not an ISO 3166-1 country code.");
        }
        if ($subdivision !== null && preg_match('/^'.$country.'-[A-Z0-9]{1,3}$/', $subdivision) !== 1) {
            throw new InvalidArgumentException("{$subdivision} is not an ISO 3166-2 subdivision of {$country}.");
        }
    }

    public function label(): string
    {
        return $this->subdivision ?? $this->country;
    }

    /** @return array{country: string, subdivision: ?string} */
    public function toArray(): array
    {
        return ['country' => $this->country, 'subdivision' => $this->subdivision];
    }
}
