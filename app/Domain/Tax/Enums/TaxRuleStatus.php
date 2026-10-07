<?php

namespace App\Domain\Tax\Enums;

/** SaaS.7: a commercial tax rule is drafted, submitted for review, verified by another operator, or retired. */
enum TaxRuleStatus: string
{
    case Draft = 'draft';
    case Review = 'review';
    case Verified = 'verified';
    case Retired = 'retired';

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Review => 'warning',
            self::Verified => 'success',
            self::Retired => 'danger',
        };
    }
}
