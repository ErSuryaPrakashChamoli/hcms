<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Attendance\Contracts\PaidDayAdjustmentReader;
use App\Domain\Employment\Models\Employee;
use App\Domain\Payroll\Models\PayrollPeriod;

/**
 * HROne parity TX-4 (owner decision TIM-2 / D-TX2): payroll's read of HR's attendance-side paid-day adjustment for an
 * employee's pay month — through the attendance contract only, as a quantity of days. While the adjustments are switched
 * off (attendance.paid_day_adjustments.enabled, the default) it answers nothing and the calculation is exactly as before;
 * payroll's own loss-of-pay adjustment stays for payroll-only corrections, and an entry with both is flagged.
 */
final class AttendancePaidDayBasis
{
    public const EXCEPTION = 'paid_days_adjusted_twice';

    public function __construct(private readonly PaidDayAdjustmentReader $attendance) {}

    /**
     * The attendance adjustment of the period's month for the employee, or null (switched off, or none).
     *
     * @return array{id: int, mode: string, label: string, described: string, reason: string, paid_days_before: float, paid_days_after: float, change: float, start: string, end: string}|null
     */
    public function forPeriod(Employee $employee, PayrollPeriod $period): ?array
    {
        if (! $this->attendance->inUse() || $period->company_id === null) {
            return null;
        }

        return $this->attendance->forMonth((int) $employee->id, (int) $period->company_id, (int) $period->year, (int) $period->month);
    }

    /** Loss-of-pay days the attendance adjustment adds (positive) or gives back (negative). */
    public static function lossOfPay(array $adjustment): float
    {
        return round(-(float) $adjustment['change'], 2);
    }

    /** The warning shown on an entry where both payroll's loss-of-pay adjustment and the attendance adjustment count. */
    public static function overlapMessage(float $manualLop, array $adjustment): string
    {
        $change = (float) $adjustment['change'];

        return 'Loss of pay was adjusted in payroll ('.self::days($manualLop).') and paid days were also adjusted in attendance for '.$adjustment['label']
            .' ('.($change > 0 ? '+' : '').self::days($change).': '.$adjustment['described'].'). Both count: check that the same days are not taken twice.';
    }

    private static function days(float $days): string
    {
        $text = rtrim(rtrim(number_format($days, 2, '.', ''), '0'), '.');

        return $text.' day'.(abs($days) == 1.0 ? '' : 's');
    }
}
