<?php

namespace App\Domain\Tax\Enums;

/**
 * SaaS.7: a commercial tax rule is drafted, submitted for verification (PENDING_VERIFICATION), verified by another
 * operator or rejected, and may be retired. What a verified rule means on a day (current, scheduled, superseded,
 * expired) is derived: TaxRuleState.
 */
enum TaxRuleStatus: string
{
    case Draft = 'draft';
    case Review = 'review';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Retired = 'retired';

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Review => 'warning',
            self::Verified => 'success',
            self::Rejected, self::Retired => 'danger',
        };
    }
}
