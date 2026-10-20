<?php

namespace App\Domain\Tax\Jurisdictions\UnitedArabEmirates;

use App\Domain\Tax\Contracts\TaxDeterminer;
use App\Domain\Tax\Enums\TaxIdType;
use App\Domain\Tax\Enums\TaxRegime;
use App\Domain\Tax\Enums\TaxRegistration;
use App\Domain\Tax\Enums\TaxTreatment;
use App\Domain\Tax\Exceptions\TaxUnavailableException;
use App\Domain\Tax\Support\TaxContext;
use App\Domain\Tax\Support\TaxDetermination;
use App\Domain\Tax\Support\TaxJurisdiction;

/**
 * SaaS.7 configuration: UAE VAT on electronic services supplied by a non-resident to a customer in the UAE (the
 * destination leg; place of supply where the services are used and enjoyed, taken as the customer's location on
 * record). A VAT-registered recipient (TRN) accounts for the VAT (reverse charge). Any other recipient: VAT is due
 * from the supplier, which must be registered in the UAE. Rates, citations and conditions come from the AE_VAT rule.
 */
final class UaeVatDestination implements TaxDeterminer
{
    public function regime(): TaxRegime
    {
        return TaxRegime::AeVat;
    }

    public function outcomes(): array
    {
        return ['b2b_reverse_charge' => 'VAT-registered recipient in the UAE (the recipient accounts for VAT: reverse charge)',
            'unregistered_recipient' => 'Recipient in the UAE not registered for VAT (VAT is due from the supplier, who must be registered)'];
    }

    public function determine(TaxContext $context): TaxDetermination
    {
        $customer = $context->customer;
        if ($customer->jurisdiction->country !== 'AE') {
            throw new TaxUnavailableException(TaxUnavailableException::PLACE_OF_SUPPLY_UNRESOLVED, 'UAE VAT destination rules apply to a customer in the UAE.');
        }
        if ($customer->registration === TaxRegistration::Registered && $customer->taxIdType === TaxIdType::AeTrn && $customer->taxIdValue !== null) {
            return new TaxDetermination(TaxRegime::AeVat, TaxTreatment::ReverseCharge, 'b2b_reverse_charge', new TaxJurisdiction('AE'),
                'VAT-registered recipient: the services are used and enjoyed in the UAE; the recipient accounts for the VAT', ['customer_trn' => $customer->taxIdValue]);
        }

        return new TaxDetermination(TaxRegime::AeVat, TaxTreatment::Standard, 'unregistered_recipient', new TaxJurisdiction('AE'),
            'Recipient not registered for VAT in the UAE: the services are used and enjoyed in the UAE');
    }
}
