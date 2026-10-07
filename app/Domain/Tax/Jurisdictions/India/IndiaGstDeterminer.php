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
 * SaaS.7 India GST determination for supplies by a supplier registered in an Indian state to a customer in India.
 * The place of supply is the customer's state on record (registered: the GSTIN's state, which must match;
 * unregistered or consumer: the billing address) — the general rule, applied only once a tax review verifies the
 * rule that prices it. Same state: intra_state (CGST + SGST), or intra_union_territory (CGST + UTGST) in a union
 * territory without a legislature; otherwise inter_state (IGST). Exports, SEZ and UIN supplies are refused: their
 * zero-rating or special treatment is not configured. Nothing here is a default: the rates come from the rule.
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
            'inter_state' => 'Different states (IGST)'];
    }

    public function determine(TaxContext $context): TaxDetermination
    {
        $supplier = $context->supplier;
        $customer = $context->customer;
        $supplierCode = GstStates::code($supplier->jurisdiction->subdivision);
        if ($supplier->jurisdiction->country !== 'IN' || $supplierCode === null) {
            throw new TaxUnavailableException('supplier_state', 'India GST needs a supplier in an Indian state.');
        }
        if ($supplier->taxIdType !== TaxIdType::InGstin || $supplier->taxIdValue === null || substr($supplier->taxIdValue, 0, 2) !== $supplierCode) {
            throw new TaxUnavailableException('supplier_not_registered', 'The supplier profile has no GSTIN for its state: no GST invoice can be issued.');
        }
        if ($customer->jurisdiction->country !== 'IN') {
            throw new TaxUnavailableException('export_not_configured', "Supplies to a customer in {$customer->jurisdiction->country} (export of services) are not configured: zero-rating conditions need a tax review.");
        }
        if ($customer->specialStatus !== null) {
            throw new TaxUnavailableException('special_status_not_configured', "Supplies to a customer with special status \"{$customer->specialStatus}\" (SEZ, UIN) are not configured.");
        }
        $placeCode = GstStates::code($customer->jurisdiction->subdivision);
        if ($placeCode === null) {
            throw new TaxUnavailableException('customer_state', 'The customer has no Indian state on record: the place of supply cannot be determined.');
        }
        if ($customer->registration === TaxRegistration::Registered
            && ($customer->taxIdType !== TaxIdType::InGstin || $customer->taxIdValue === null || substr($customer->taxIdValue, 0, 2) !== $placeCode)) {
            throw new TaxUnavailableException('customer_gstin_state', 'A registered customer needs a GSTIN of the state on record.');
        }

        $outcome = $placeCode !== $supplierCode ? 'inter_state'
            : (GstStates::isUnionTerritoryWithoutLegislature($customer->jurisdiction->subdivision) ? 'intra_union_territory' : 'intra_state');
        $place = new TaxJurisdiction('IN', GstStates::canonical($customer->jurisdiction->subdivision));

        return new TaxDetermination(TaxRegime::InGst, TaxTreatment::Standard, $outcome, $place,
            'Place of supply: the recipient\'s state on record ('.($customer->registration === TaxRegistration::Registered ? 'GSTIN' : 'billing address').') [pending tax review]',
            ['supplier_state_code' => $supplierCode, 'supplier_gstin' => $supplier->taxIdValue, 'place_of_supply_code' => $placeCode,
                'place_of_supply_name' => GstStates::name($customer->jurisdiction->subdivision), 'customer_gstin' => $customer->taxIdValue,
                'customer_registration' => $customer->registration->value]);
    }
}
