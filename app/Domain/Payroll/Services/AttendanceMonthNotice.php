<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Attendance\Contracts\AttendancePeriodState;
use App\Domain\Payroll\Models\PayrollRun;

/**
 * HROne parity TX-3 (owner decision TIM-1 / D-TX1): with attendance month close in use, payroll warns about a pay month
 * whose attendance is not closed yet — it never waits for it. Read through the attendance contract only; nothing is
 * shown while month close is off.
 */
final class AttendanceMonthNotice
{
    public function __construct(private readonly AttendancePeriodState $attendance) {}

    /** "Attendance for Oct 2026 is not closed yet …", or null (closed, or month close not in use). */
    public function forMonth(int $companyId, int $year, int $month, ?string $company = null): ?string
    {
        $state = $this->attendance->monthState($companyId, $year, $month);
        if ($state === null || $state['status'] === 'closed') {
            return null;
        }

        return ($company !== null ? $company.': a' : 'A').'ttendance for '.$state['label'].' is not closed yet (Attendance → Run attendance). Payroll can run; attendance may still change until the month is closed.';
    }

    public function forRun(PayrollRun $run): ?string
    {
        $run->loadMissing('period');

        return $run->period === null ? null : $this->forMonth((int) $run->company_id, (int) $run->period->year, (int) $run->period->month);
    }
}
