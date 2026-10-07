<?php

namespace App\Domain\Tax\Enums;

/**
 * SaaS.7: indirect-tax regimes PeopleOS can represent. Representable is not supported: a regime is applied only
 * through a determiner (code, per jurisdiction) and a verified rule (data, per tax review). Only IN_GST has a
 * determiner today; every other regime refuses invoice issue until one is built and its rules are verified.
 */
enum TaxRegime: string
{
    case InGst = 'IN_GST';
    case EuVat = 'EU_VAT';
    case GbVat = 'GB_VAT';
    case AeVat = 'AE_VAT';
    case UsSalesTax = 'US_SALES_TAX';
    case CaSalesTax = 'CA_SALES_TAX';
    case AuGst = 'AU_GST';
    case SgGst = 'SG_GST';

    public function label(): string
    {
        return match ($this) {
            self::InGst => 'India GST (CGST / SGST / UTGST / IGST)',
            self::EuVat => 'EU VAT',
            self::GbVat => 'UK VAT',
            self::AeVat => 'UAE VAT',
            self::UsSalesTax => 'US sales tax',
            self::CaSalesTax => 'Canada GST / HST / PST / QST',
            self::AuGst => 'Australia GST',
            self::SgGst => 'Singapore GST',
        };
    }

    /** @return list<string> the tax types a rule of this regime may name */
    public function taxTypes(): array
    {
        return match ($this) {
            self::InGst => ['CGST', 'SGST', 'UTGST', 'IGST', 'CESS'],
            self::EuVat, self::GbVat, self::AeVat => ['VAT'],
            self::UsSalesTax => ['STATE', 'COUNTY', 'CITY', 'DISTRICT'],
            self::CaSalesTax => ['GST', 'HST', 'PST', 'QST'],
            self::AuGst, self::SgGst => ['GST'],
        };
    }

    /** @return list<string> classification keys a rule of this regime must carry (e.g. the SAC on an Indian invoice) */
    public function requiredClassification(): array
    {
        return match ($this) {
            self::InGst => ['sac'],
            default => [],
        };
    }
}
