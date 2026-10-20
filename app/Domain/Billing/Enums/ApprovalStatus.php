<?php

namespace App\Domain\Billing\Enums;

/** SaaS.7 completion (B-13): pending → approved (and executed in the same transaction) | rejected | withdrawn. Final states never change. */
enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Withdrawn => 'gray',
        };
    }
}
