<?php

namespace App\Domain\Subscriptions\Enums;

/**
 * SaaS.6: a subscription's commercial state on a day. Commercial, never technical: it never changes tenants.status
 * and never decides authorisation.
 *
 * - trial: a trial of a plan version, between explicit dates.
 * - active: the plan version is in force (open-ended or until an explicit end).
 * - grace: an explicit, dated window after a lapse of terms, still entitled (entered by an operator).
 * - expired: the trial, term or grace ended without a successor. It can be reactivated.
 * - cancelled: terminal for this subscription; a returning customer gets a new subscription.
 */
enum CommercialStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case Grace = 'grace';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    /** Whether the plan version is in force (projected onto a plan assignment). */
    public function entitled(): bool
    {
        return in_array($this, [self::Trial, self::Active, self::Grace], true);
    }

    public function terminal(): bool
    {
        return $this === self::Cancelled;
    }

    public function color(): string
    {
        return match ($this) {
            self::Trial => 'info',
            self::Active => 'success',
            self::Grace => 'warning',
            self::Expired => 'gray',
            self::Cancelled => 'danger',
        };
    }
}
