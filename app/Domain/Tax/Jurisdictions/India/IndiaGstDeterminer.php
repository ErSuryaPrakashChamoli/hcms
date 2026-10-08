<?php

namespace App\Domain\Tax\Jurisdictions\India;

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
 * SaaS.7 India GST determination for supplies by a supplier registered in an Indian state. Customer in India: the
 * place of supply is the customer's state on record (registered: the GSTIN's state, which must match; unregistered:
 * the billing address). Same state: intra_state (CGST + SGST), or intra_union_territory (CGST + UTGST) in a union
 * territory without a legislature; otherwise inter_state (IGST). Customer outside India: export_of_services, the
 * place of supply being the recipient's location; whether it is zero-rated is decided by the rule's configured
 * export conditions (recipient abroad, payment in foreign exchange, a valid LUT …), never assumed here, and the
 * destination jurisdiction's treatment is evaluated too. SEZ and UIN supplies are refused (not configured). Nothing
 * here is a rate, a citation or a default: they come from the verified rule.
 */
final class IndiaGstDeterminer implements TaxDeterminer
{
    public function regime(): TaxRegime
    {
        return TaxRegime::InGst;
    }

    public function outcomes(): array
    {
        return ['intra_state' => 'Same state (CGST + SGST)', 'intra_union_territory' => 'Same union territory without legislature (CGST + UTGST)',
            'inter_state' => 'Different states (IGST)', 'export_of_services' => 'Recipient outside India (export of services; zero-rated only when its conditions are met)'];
    }

    public function determine(TaxContext $context): TaxDetermination
    {
        $supplier = $context->supplier;
        $customer = $context->customer;
        $supplierCode = GstStates::code($supplier->jurisdiction->subdivision);
        if ($supplier->jurisdiction->country !== 'IN' || $supplierCode === null) {
            throw new TaxUnavailableException(TaxUnavailableException::SUPPLIER_TAX_STATUS_UNRESOLVED, 'India GST needs a supplier in an Indian state.');
        }
        if ($supplier->taxIdType !== TaxIdType::InGstin || $supplier->taxIdValue === null || substr($supplier->taxIdValue, 0, 2) !== $supplierCode) {
            throw new TaxUnavailableException(TaxUnavailableException::SUPPLIER_TAX_STATUS_UNRESOLVED, 'The supplier profile has no GSTIN for its state: no GST invoice can be issued.');
        }
        if ($customer->jurisdiction->country !== 'IN') {
            return new TaxDetermination(TaxRegime::InGst, TaxTreatment::ZeroRated, 'export_of_services', $customer->jurisdiction,
                "Place of supply: the recipient's location outside India ({$customer->jurisdiction->country})",
                ['supplier_state_code' => $supplierCode, 'supplier_gstin' => $supplier->taxIdValue, 'place_of_supply_country' => $customer->jurisdiction->country,
                    'customer_location' => $customer->jurisdiction->subdivision ?? $customer->jurisdiction->country], requiresDestination: true);
        }
        if ($customer->specialStatus !== null) {
            throw new TaxUnavailableException(TaxUnavailableException::TAX_CONFIGURATION_MISSING, "Supplies to a customer with special status \"{$customer->specialStatus}\" (SEZ, UIN) are not configured.");
        }
        $placeCode = GstStates::code($customer->jurisdiction->subdivision);
        if ($placeCode === null) {
            throw new TaxUnavailableException(TaxUnavailableException::PLACE_OF_SUPPLY_UNRESOLVED, 'The customer has no Indian state on record: the place of supply cannot be determined.');
        }
        if ($customer->registration === TaxRegistration::Registered
            && ($customer->taxIdType !== TaxIdType::InGstin || $customer->taxIdValue === null || substr($customer->taxIdValue, 0, 2) !== $placeCode)) {
            throw new TaxUnavailableException(TaxUnavailableException::CUSTOMER_TAX_STATUS_UNRESOLVED, 'A registered customer needs a GSTIN of the state on record.');
        }

        $outcome = $placeCode !== $supplierCode ? 'inter_state'
            : (GstStates::isUnionTerritoryWithoutLegislature($customer->jurisdiction->subdivision) ? 'intra_union_territory' : 'intra_state');
        $place = new TaxJurisdiction('IN', GstStates::canonical($customer->jurisdiction->subdivision));

        return new TaxDetermination(TaxRegime::InGst, TaxTreatment::Standard, $outcome, $place,
            'Place of supply: the recipient\'s state on record ('.($customer->registration === TaxRegistration::Registered ? 'GSTIN' : 'billing address').')',
            ['supplier_state_code' => $supplierCode, 'supplier_gstin' => $supplier->taxIdValue, 'place_of_supply_code' => $placeCode,
                'place_of_supply_name' => GstStates::name($customer->jurisdiction->subdivision), 'customer_gstin' => $customer->taxIdValue,
                'customer_registration' => $customer->registration->value]);
    }
}
