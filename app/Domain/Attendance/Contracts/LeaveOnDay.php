<?php

namespace App\Domain\Attendance\Contracts;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use Carbon\CarbonInterface;

/**
 * HROne parity TX-7 (matrix 24 §24.5.2 #3, the day drawer's "Cancel leave"): Attendance asks Leave which leave requests
 * touch a day — approved, waiting for a decision, or with a cancellation waiting — and has Leave cancel one through its own
 * cancellation path (the leave policy decides who may; balances and the day's recalculation stay in the Leave domain).
 * Attendance never reads or writes leave tables itself.
 */
interface LeaveOnDay
{
    /**
     * The leave requests of $employee covering $date that $viewer may see (in their scope, or their own).
     *
     * @return list<array{id: int, type: string, code: ?string, from: string, to: string, days: float, session: string, status: string, status_label: string, paid: bool, reason: ?string, can_cancel: bool}>
     */
    public function onDay(Employee $employee, CarbonInterface $date, ?User $viewer = null): array;

    /**
     * Cancel the request through the leave domain's own cancellation (Leaves::cancel), when $actor may cancel it. Returns
     * what happened, for people ("Leave cancelled", "Cancellation requested").
     */
    public function cancel(int $leaveRequestId, string $reason, User $actor): string;
}
