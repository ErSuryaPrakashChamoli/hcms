<?php

namespace App\Domain\Tax\Enums;

/**
 * SaaS.7: what PeopleOS can honestly say about a jurisdiction. Technical support is not legal compliance:
 * `supported` requires a tax and legal approval outside the software and is never computed; software reports at
 * most `configured` (a determiner exists and a verified rule is in force).
 */
enum JurisdictionStatus: string
{
    case Supported = 'supported';
    case Configured = 'configured';
    case PendingTaxReview = 'pending_tax_review';
    case NotSupported = 'not_supported';
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return match ($this) {
            self::Supported => 'Supported (tax and legal approval recorded)',
            self::Configured => 'Configured (verified rule in force; not a legal approval)',
            self::PendingTaxReview => 'Pending tax review (determination built; no verified rule)',
            self::NotSupported => 'Not supported (architecture-ready; tax rules not configured)',
            self::NotApplicable => 'Not applicable',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Supported, self::Configured => 'success',
            self::PendingTaxReview => 'warning',
            self::NotSupported, self::NotApplicable => 'gray',
        };
    }
}
