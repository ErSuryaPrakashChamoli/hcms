<?php

namespace App\Domain\Payments\Enums;

/** SaaS.7: whether a payment's money is matched to its invoice, an exception for an operator, or an exception resolved. */
enum ReconciliationStatus: string
{
    case Unreconciled = 'unreconciled';
    case Matched = 'matched';
    case Exception = 'exception';
    case Resolved = 'resolved';

    public function color(): string
    {
        return match ($this) {
            self::Unreconciled => 'gray',
            self::Matched => 'success',
            self::Exception => 'danger',
            self::Resolved => 'info',
        };
    }
}
