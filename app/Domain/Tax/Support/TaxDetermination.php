<?php

namespace App\Domain\Tax\Support;

use App\Domain\Tax\Enums\TaxRegime;
use App\Domain\Tax\Enums\TaxTreatment;

/**
 * SaaS.7: the explainable outcome of a regime's determination: treatment, the outcome key the verified rule prices
 * (e.g. intra_state / inter_state), the place of supply and the basis in words, plus jurisdiction metadata.
 */
final readonly class TaxDetermination
{
    /** @param  array<string, mixed>  $metadata */
    public function __construct(
        public TaxRegime $regime,
        public TaxTreatment $treatment,
        public string $outcome,
        public TaxJurisdiction $placeOfSupply,
        public string $basis,
        public array $metadata = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['regime' => $this->regime->value, 'treatment' => $this->treatment->value, 'outcome' => $this->outcome,
            'place_of_supply' => $this->placeOfSupply->toArray(), 'basis' => $this->basis, 'metadata' => $this->metadata];
    }
}
