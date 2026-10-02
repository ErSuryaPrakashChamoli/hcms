<?php

namespace App\Domain\ServiceDesk\DomainActions;

/**
 * Phase 12: a profile change (People / Employment data). It is requested by the employee for their own
 * record, or by HR on their behalf; never by a manager for a report. HR executes it after the request
 * is ready (approved where the service requires approval). The values are sensitive: the requester sees
 * them masked, and they are purged from the request once the change is executed, rejected or cancelled.
 */
abstract class ProfileChangeHandler extends BaseDomainAction
{
    protected array $sources = ['web', 'hr'];

    public function timing(): string
    {
        return 'after_approval';
    }

    /** "Service request TKT-…" — the reason recorded on the profile audit. */
    protected function reason(string $number): string
    {
        return 'Service request '.$number;
    }
}
