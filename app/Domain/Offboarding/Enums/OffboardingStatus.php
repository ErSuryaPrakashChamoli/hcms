<?php

namespace App\Domain\Offboarding\Enums;

/** SaaS.9: an offboarding case. requested (the tenant still works) → closed (retention) → completed; cancelled any time before a deletion starts. */
enum OffboardingStatus: string
{
    case Requested = 'requested';
    case Closed = 'closed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function open(): bool
    {
        return $this === self::Requested || $this === self::Closed;
    }
}
