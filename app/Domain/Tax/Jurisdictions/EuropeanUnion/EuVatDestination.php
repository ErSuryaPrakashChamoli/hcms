<?php

namespace App\Domain\Tax\Jurisdictions\EuropeanUnion;

use App\Domain\Tax\Contracts\TaxDeterminer;
use App\Domain\Tax\Enums\CustomerType;
use App\Domain\Tax\Enums\TaxRegime;
use App\Domain\Tax\Enums\TaxTreatment;
use App\Domain\Tax\Exceptions\TaxUnavailableException;
use App\Domain\Tax\Services\JurisdictionCatalogue;
use App\Domain\Tax\Support\TaxContext;
use App\Domain\Tax\Support\TaxDetermination;
use App\Domain\Tax\Support\TaxJurisdiction;

/**
 * SaaS.7 configuration: VAT of an EU member state on a service supplied from outside the EU to a customer in that
 * member state (the destination leg). Always member-state specific: the rule is the member state's own (its rate,
 * its evidence conditions). A business customer: the place of supply is the customer's establishment and the
 * customer accounts for the VAT (reverse charge). A consumer: the member state's VAT is due from the supplier, which
 * must be registered (the non-Union scheme or in the member state).
 */
final class EuVatDestination implements TaxDeterminer
{
    public function regime(): TaxRegime
    {
        return TaxRegime::EuVat;
    }

    public function outcomes(): array
    {
        return ['b2b_reverse_charge' => 'Business customer in the member state (the customer accounts for VAT: reverse charge)',
            'b2c_electronic_services' => 'Consumer in the member state (its VAT is due from the supplier, who must be registered)'];
    }

    public function determine(TaxContext $context): TaxDetermination
    {
        $customer = $context->customer;
        $country = $customer->jurisdiction->country;
        if (! in_array($country, JurisdictionCatalogue::EU_MEMBERS, true)) {
            throw new TaxUnavailableException(TaxUnavailableException::PLACE_OF_SUPPLY_UNRESOLVED, "{$country} is not an EU member state.");
        }

        return match ($customer->customerType) {
            CustomerType::Business => new TaxDetermination(TaxRegime::EuVat, TaxTreatment::ReverseCharge, 'b2b_reverse_charge', new TaxJurisdiction($country),
                "Business customer: the place of supply is the customer's establishment ({$country})", ['customer_vat_id' => $customer->taxIdValue]),
            CustomerType::Consumer => new TaxDetermination(TaxRegime::EuVat, TaxTreatment::Standard, 'b2c_electronic_services', new TaxJurisdiction($country),
                "Consumer: the place of supply is where the customer resides ({$country})"),
            default => throw new TaxUnavailableException(TaxUnavailableException::CUSTOMER_TAX_STATUS_UNRESOLVED, 'Whether the EU customer is a business or a consumer is not recorded.'),
        };
    }
}
