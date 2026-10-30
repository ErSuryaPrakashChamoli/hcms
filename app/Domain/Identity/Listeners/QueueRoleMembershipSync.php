<?php

namespace App\Domain\Identity\Listeners;

use App\Domain\Employment\Events\EmploymentEvent;
use App\Domain\Identity\Jobs\SyncRoleMemberships;
use App\Domain\Identity\Services\RoleMembershipSync;
use App\Domain\Lifecycle\Events\EmployeeLifecycleChanged;

/**
 * HROne parity SV-7: role membership follows people as they join, move and leave. A lifecycle change or a change of
 * position queues the rules for that employee (after the change commits); tenants without rules queue nothing.
 */
final class QueueRoleMembershipSync
{
    /** Employment events that can change what a rule sees about the employee. */
    public const EVENTS = ['employee.created', 'employee.transferred', 'employee.promoted', 'employee.department_changed',
        'employee.designation_changed', 'employee.location_changed', 'employee.company_changed', 'employee.rehired'];

    public function __construct(private readonly RoleMembershipSync $sync) {}

    public function handle(EmployeeLifecycleChanged|EmploymentEvent $event): void
    {
        if ($event instanceof EmploymentEvent && ! in_array($event->name, self::EVENTS, true)) {
            return;
        }
        if (! $this->sync->hasRules()) {
            return;
        }
        SyncRoleMemberships::dispatch([(int) $event->employee->getKey()])->afterCommit();
    }
}
