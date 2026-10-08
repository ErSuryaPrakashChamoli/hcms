<?php

namespace App\Domain\Offboarding\Enums;

/**
 * SaaS.9: a tenant deletion. planned (maker; the reviewed plan) → approved (checker; scheduled after the cooling-off) →
 * in_progress (the worker) → completed (certified); failed (resumable: retried by an operator); rejected; cancelled
 * (before it starts).
 */
enum DeletionStatus: string
{
    case Planned = 'planned';
    case Approved = 'approved';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Failed = 'failed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /** Holds the tenant's one deletion slot (in_flight_key). */
    public function inFlight(): bool
    {
        return in_array($this, [self::Planned, self::Approved, self::InProgress, self::Failed], true);
    }
}
