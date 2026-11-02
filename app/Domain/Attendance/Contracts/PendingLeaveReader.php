<?php

namespace App\Domain\Attendance\Contracts;

use Carbon\CarbonInterface;

/**
 * HROne parity TX-3: Attendance asks Leave which leave requests are still waiting for a decision (a new request, or the
 * cancellation of an approved one) on days of a window — the month desk lists them and closing a month can wait for
 * them. Read only; approvals and balances stay in the Leave domain.
 */
interface PendingLeaveReader
{
    /**
     * @param  list<int>  $employeeIds
     * @param  bool  $scoped  false = every request, also those outside the viewer's access scope
     * @return list<array{id: int, employee_id: int, from: string, to: string, label: string, status: string}>
     */
    public function pendingBetween(array $employeeIds, CarbonInterface $from, CarbonInterface $to, bool $scoped = true): array;
}
