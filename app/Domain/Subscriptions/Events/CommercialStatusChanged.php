<?php

namespace App\Domain\Subscriptions\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * SaaS.6: the notification boundary of the commercial lifecycle. Dispatched after commit, once per recorded change
 * (an operator transition or a scheduled expiry). SaaS.6 registers no listener that sends anything: reminders and
 * owner notices need a reminder policy (D-4) and an owner contact, which do not exist yet. Carries identifiers and
 * states only (no personal data, no money).
 */
final class CommercialStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $subscriptionId,
        public readonly string $action,
        public readonly ?string $from,
        public readonly string $to,
        public readonly string $effectiveOn,
        public readonly string $trigger,
    ) {}
}
