<?php

namespace App\Domain\Tax\Jurisdictions\UnitedKingdom;

use App\Domain\Tax\Contracts\TaxDeterminer;
use App\Domain\Tax\Enums\CustomerType;
use App\Domain\Tax\Enums\TaxRegime;
use App\Domain\Tax\Enums\TaxTreatment;
use App\Domain\Tax\Exceptions\TaxUnavailableException;
use App\Domain\Tax\Support\TaxContext;
use App\Domain\Tax\Support\TaxDetermination;
use App\Domain\Tax\Support\TaxJurisdiction;

/**
 * SaaS.7 configuration: UK VAT on a service supplied from abroad to a customer in the UK (the destination leg).
 * A business customer: the place of supply is where the customer belongs and the customer accounts for the VAT
 * (reverse charge). A consumer: UK VAT is due from the supplier, which must be registered. Rates, citations,
 * evidence conditions and invoice wording come from the verified GB_VAT rule.
 */
final class UkVatDestination implements TaxDeterminer
{
    public function regime(): TaxRegime
    {
        return TaxRegime::GbVat;
    }

    public function outcomes(): array
    {
        return ['b2b_reverse_charge' => 'Business customer in the UK (the customer accounts for UK VAT: reverse charge)',
            'b2c_digital_services' => 'Consumer in the UK (UK VAT is due from the supplier, who must be UK VAT-registered)'];
    }

    public function determine(TaxContext $context): TaxDetermination
    {
        $customer = $context->customer;
        if ($customer->jurisdiction->country !== 'GB') {
            throw new TaxUnavailableException(TaxUnavailableException::PLACE_OF_SUPPLY_UNRESOLVED, 'UK VAT destination rules apply to a customer in the UK.');
        }

        return match ($customer->customerType) {
            CustomerType::Business => new TaxDetermination(TaxRegime::GbVat, TaxTreatment::ReverseCharge, 'b2b_reverse_charge', new TaxJurisdiction('GB'),
                'Business customer: the place of supply is where the customer belongs (the UK)', ['customer_vat_number' => $customer->taxIdValue]),
            CustomerType::Consumer => new TaxDetermination(TaxRegime::GbVat, TaxTreatment::Standard, 'b2c_digital_services', new TaxJurisdiction('GB'),
                'Consumer: the place of supply is where the consumer belongs (the UK)'),
            default => throw new TaxUnavailableException(TaxUnavailableException::CUSTOMER_TAX_STATUS_UNRESOLVED, 'Whether the UK customer is a business or a consumer is not recorded.'),
        };
    }
}
