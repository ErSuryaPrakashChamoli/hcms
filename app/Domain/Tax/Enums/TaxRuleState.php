<?php

namespace App\Domain\Tax\Enums;

/**
 * SaaS.7 configuration: what a tax rule version means on a given day. Derived, never stored: CURRENT is the verified
 * version in force for its scope; SCHEDULED starts later; SUPERSEDED was replaced by a later verified version;
 * EXPIRED passed its end date; PENDING_VERIFICATION waits for a second operator. Only CURRENT ever prices an invoice.
 */
enum TaxRuleState: string
{
    case Draft = 'DRAFT';
    case PendingVerification = 'PENDING_VERIFICATION';
    case Scheduled = 'SCHEDULED';
    case Current = 'CURRENT';
    case Superseded = 'SUPERSEDED';
    case Expired = 'EXPIRED';
    case Rejected = 'REJECTED';
    case Retired = 'RETIRED';

    public function label(): string
    {
        return str_replace('_', ' ', $this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Current => 'success',
            self::Scheduled => 'info',
            self::PendingVerification => 'warning',
            self::Draft, self::Superseded, self::Expired => 'gray',
            self::Rejected, self::Retired => 'danger',
        };
    }
}
