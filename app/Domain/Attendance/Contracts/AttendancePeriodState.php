<?php

namespace App\Domain\Attendance\Contracts;

/**
 * HROne parity TX-3: read contract for modules that consume a company's attendance month (they warn while it is still
 * open; they never close, reopen or block on it). Implemented by AttendanceMonthCloses.
 */
interface AttendancePeriodState
{
    /** Whether HR closes attendance months in this organisation (setting attendance.month_close.enabled). */
    public function closingInUse(): bool;

    /**
     * The month's state, or null while month close is not in use.
     *
     * @return array{status: 'open'|'closed', label: string, closed_at: ?string, closed_by: ?string}|null
     */
    public function monthState(int $companyId, int $year, int $month): ?array;
}
