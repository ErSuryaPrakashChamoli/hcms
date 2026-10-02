<?php

namespace App\Domain\Payroll\Contracts;

use Carbon\CarbonInterface;

/**
 * Phase 11 read contract from Payroll to Compensation: whether an employee's payroll is already closed
 * on or after a date. Compensation calls it inside the transaction that writes the canonical
 * compensation timeline; the lock it takes is what serialises that write with payroll finalisation.
 */
interface PayrollClosureReader
{
    /**
     * Lock (FOR UPDATE) the payroll runs of periods ending on or after $from, so a concurrent
     * finalisation waits for the caller's transaction (and vice versa), and return the latest end date
     * of such a period whose finalized or paid run holds the employee — null when none does.
     */
    public function closedOnOrAfter(int $employeeId, CarbonInterface $from): ?CarbonInterface;

    /**
     * The latest end date of any finalized or paid payroll period of the tenant (null when none). A
     * structure version affects everyone on the structure, so it must start after this date.
     */
    public function latestClosedPeriodEnd(): ?CarbonInterface;
}
