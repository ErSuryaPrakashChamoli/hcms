<?php

namespace App\Domain\Payroll\Inputs;

use App\Domain\Leave\Contracts\EncashmentsBeingPaid;

/**
 * HROne parity PO-8: the leave encashments a payroll run not yet finalized has calculated a line for (LeaveEncashmentInput,
 * key `leave_encashment`), read like every other input source through PendingPayrollInputs. A batch unprocess leaves
 * them alone; finalizing the run marks them paid.
 */
final class PendingEncashmentPayments implements EncashmentsBeingPaid
{
    public function __construct(private readonly PendingPayrollInputs $pending) {}

    public function for(int $employeeId): array
    {
        return $this->pending->pending('leave_encashment', $employeeId);
    }
}
