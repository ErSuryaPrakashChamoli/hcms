<?php

namespace App\Domain\Tax\Jurisdictions\UnitedStates;

use App\Domain\Tax\Contracts\TaxDeterminer;
use App\Domain\Tax\Enums\TaxRegime;
use App\Domain\Tax\Enums\TaxTreatment;
use App\Domain\Tax\Exceptions\TaxUnavailableException;
use App\Domain\Tax\Support\TaxContext;
use App\Domain\Tax\Support\TaxDetermination;
use App\Domain\Tax\Support\TaxJurisdiction;

/**
 * SaaS.7 configuration: US sales tax on SaaS sold to a customer in a US state (the destination leg). There is no
 * national rate: the customer's state on record selects the state's own rule, whose data says whether SaaS is
 * taxable there, at which state and local rates, on which share of the price, and whether collection requires the
 * seller's registration in that state. A customer without a state cannot be resolved.
 */
final class UsSalesTaxDestination implements TaxDeterminer
{
    public function regime(): TaxRegime
    {
        return TaxRegime::UsSalesTax;
    }

    public function outcomes(): array
    {
        return ['saas' => 'SaaS sold to a customer in the state (taxability, rates and registration from the state\'s rule)'];
    }

    public function determine(TaxContext $context): TaxDetermination
    {
        $place = $context->customer->jurisdiction;
        if ($place->country !== 'US' || $place->subdivision === null) {
            throw new TaxUnavailableException(TaxUnavailableException::PLACE_OF_SUPPLY_UNRESOLVED, 'A US customer needs its state on record: there is no national US sales tax.');
        }

        return new TaxDetermination(TaxRegime::UsSalesTax, TaxTreatment::Standard, 'saas', new TaxJurisdiction('US', $place->subdivision),
            "Customer location: {$place->subdivision}");
    }
}
