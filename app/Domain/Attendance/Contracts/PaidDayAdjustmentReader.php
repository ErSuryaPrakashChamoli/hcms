<?php

namespace App\Domain\Attendance\Contracts;

/**
 * HROne parity TX-4 (owner decision TIM-2 / D-TX2): read contract for modules that consume an employee's attendance month
 * and need HR's paid-day adjustment for it — as a quantity of days, never anything else. Implemented by
 * PaidDayAdjustments; answers nothing while attendance.paid_day_adjustments.enabled is off.
 */
interface PaidDayAdjustmentReader
{
    /** Whether paid-day adjustments are switched on in this organisation. */
    public function inUse(): bool;

    /**
     * The adjustment counting for the employee's attendance month of a company, or null (switched off, or none).
     * paid_days_before / paid_days_after are the month's paid days as processed and after the adjustment (late-mark
     * penalty days are a separate quantity, part of neither); change = after − before (negative: fewer paid days).
     *
     * @return array{id: int, mode: string, label: string, described: string, reason: string, paid_days_before: float, paid_days_after: float, change: float, start: string, end: string}|null
     */
    public function forMonth(int $employeeId, int $companyId, int $year, int $month): ?array;
}
