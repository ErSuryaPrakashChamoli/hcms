<?php

namespace App\Domain\Tax\Enums;

/**
 * SaaS.7: generic tax-registration identifiers. A format check exists only where a jurisdiction module provides a
 * validator (India GSTIN); every other identifier is stored as given and marked "not validated". No identifier is
 * verified live against a tax authority in SaaS.7.
 */
enum TaxIdType: string
{
    case InGstin = 'IN_GSTIN';
    case EuVatId = 'EU_VAT_ID';
    case GbVat = 'GB_VAT';
    case AeTrn = 'AE_TRN';
    case AuAbn = 'AU_ABN';
    case SgGst = 'SG_GST';
    case CaBn = 'CA_BN';
    case UsSalesTaxPermit = 'US_SALES_TAX_PERMIT';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::InGstin => 'India GSTIN',
            self::EuVatId => 'EU VAT identification number',
            self::GbVat => 'UK VAT registration number',
            self::AeTrn => 'UAE tax registration number (TRN)',
            self::AuAbn => 'Australian business number (ABN)',
            self::SgGst => 'Singapore GST registration number',
            self::CaBn => 'Canada business number (GST/HST)',
            self::UsSalesTaxPermit => 'US state sales-tax permit',
            self::Other => 'Other tax identifier',
        };
    }

    /** @return list<string>|null ISO 3166-1 countries that issue it; null = any */
    public function issuers(): ?array
    {
        return match ($this) {
            self::InGstin => ['IN'],
            self::EuVatId => \App\Domain\Tax\Services\JurisdictionCatalogue::EU_MEMBERS,
            self::GbVat => ['GB'],
            self::AeTrn => ['AE'],
            self::AuAbn => ['AU'],
            self::SgGst => ['SG'],
            self::CaBn => ['CA'],
            self::UsSalesTaxPermit => ['US'],
            self::Other => null,
        };
    }
}
