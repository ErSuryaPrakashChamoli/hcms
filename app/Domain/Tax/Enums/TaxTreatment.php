<?php

namespace App\Domain\Tax\Enums;

/** SaaS.7: how a supply is treated. Only `standard` produces tax lines from rates; the others may carry none. */
enum TaxTreatment: string
{
    case Standard = 'standard';
    case ZeroRated = 'zero_rated';
    case Exempt = 'exempt';
    case ReverseCharge = 'reverse_charge';
    case OutOfScope = 'out_of_scope';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Standard taxable',
            self::ZeroRated => 'Zero-rated',
            self::Exempt => 'Exempt',
            self::ReverseCharge => 'Reverse charge',
            self::OutOfScope => 'Out of scope',
        };
    }
}
