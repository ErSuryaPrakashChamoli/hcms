<?php

namespace App\Domain\Tax\Services;

use App\Domain\Tax\Enums\TaxIdType;
use App\Domain\Tax\Enums\TaxRegime;

/**
 * SaaS.7: the code-owned map of jurisdictions PeopleOS knows how to describe: the regime of each country, its
 * registration identifier, its customer distinction and what is known about invoice requirements. Describing a
 * jurisdiction is not supporting it: support status is computed by the TaxEngine (determiner + verified rules)
 * and never claims legal compliance. Countries not listed have no regime here and are not supported.
 */
final class JurisdictionCatalogue
{
    public const EU_MEMBERS = ['AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE'];

    /**
     * @return array<string, array{name: string, countries: list<string>, currency: string, regime: TaxRegime, registration: TaxIdType,
     *     customers: string, invoice_requirements: string, invoice_number_max_length: ?int}>
     */
    public static function all(): array
    {
        return [
            'IN' => ['name' => 'India', 'countries' => ['IN'], 'currency' => 'INR', 'regime' => TaxRegime::InGst, 'registration' => TaxIdType::InGstin,
                'customers' => 'Registered (GSTIN) and unregistered businesses; consumers',
                'invoice_requirements' => 'Tax invoice content, consecutive series of at most 16 characters per financial year [pending tax review]',
                'invoice_number_max_length' => 16],
            'EU' => ['name' => 'European Union', 'countries' => self::EU_MEMBERS, 'currency' => 'EUR (most members)', 'regime' => TaxRegime::EuVat, 'registration' => TaxIdType::EuVatId,
                'customers' => 'Businesses with a VAT ID (reverse charge across borders) and consumers', 'invoice_requirements' => 'Not configured', 'invoice_number_max_length' => null],
            'GB' => ['name' => 'United Kingdom', 'countries' => ['GB'], 'currency' => 'GBP', 'regime' => TaxRegime::GbVat, 'registration' => TaxIdType::GbVat,
                'customers' => 'VAT-registered businesses and consumers', 'invoice_requirements' => 'Not configured', 'invoice_number_max_length' => null],
            'AE' => ['name' => 'United Arab Emirates', 'countries' => ['AE'], 'currency' => 'AED', 'regime' => TaxRegime::AeVat, 'registration' => TaxIdType::AeTrn,
                'customers' => 'Registered businesses (TRN) and consumers', 'invoice_requirements' => 'Not configured', 'invoice_number_max_length' => null],
            'US' => ['name' => 'United States', 'countries' => ['US'], 'currency' => 'USD', 'regime' => TaxRegime::UsSalesTax, 'registration' => TaxIdType::UsSalesTaxPermit,
                'customers' => 'Businesses (exemption certificates) and consumers; state nexus decides', 'invoice_requirements' => 'Not configured', 'invoice_number_max_length' => null],
            'CA' => ['name' => 'Canada', 'countries' => ['CA'], 'currency' => 'CAD', 'regime' => TaxRegime::CaSalesTax, 'registration' => TaxIdType::CaBn,
                'customers' => 'Registered businesses and consumers; province decides GST/HST/PST/QST', 'invoice_requirements' => 'Not configured', 'invoice_number_max_length' => null],
            'AU' => ['name' => 'Australia', 'countries' => ['AU'], 'currency' => 'AUD', 'regime' => TaxRegime::AuGst, 'registration' => TaxIdType::AuAbn,
                'customers' => 'Businesses (ABN) and consumers', 'invoice_requirements' => 'Not configured', 'invoice_number_max_length' => null],
            'SG' => ['name' => 'Singapore', 'countries' => ['SG'], 'currency' => 'SGD', 'regime' => TaxRegime::SgGst, 'registration' => TaxIdType::SgGst,
                'customers' => 'GST-registered businesses and consumers', 'invoice_requirements' => 'Not configured', 'invoice_number_max_length' => null],
        ];
    }

    /** @return array{key: string, name: string, countries: list<string>, currency: string, regime: TaxRegime, registration: TaxIdType, customers: string, invoice_requirements: string, invoice_number_max_length: ?int}|null */
    public static function forCountry(string $country): ?array
    {
        foreach (self::all() as $key => $entry) {
            if (in_array($country, $entry['countries'], true)) {
                return ['key' => $key] + $entry;
            }
        }

        return null;
    }

    public static function regimeFor(string $country): ?TaxRegime
    {
        return self::forCountry($country)['regime'] ?? null;
    }
}
