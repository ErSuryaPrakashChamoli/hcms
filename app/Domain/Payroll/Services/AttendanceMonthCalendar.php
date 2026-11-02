<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Attendance\Contracts\AttendanceCycle;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\PayrollRun;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * HROne parity TX-3: Payroll owns the pay cycle, so it answers Attendance's month questions (AttendanceCycle). A month
 * is the company's payroll month: its fixed dates once a run exists (or it closed), else the pay cycle's; it is final
 * once a finalized (or paid) payroll covers it — finalized payroll is never reopened (PAY-1 / D-TX6).
 */
final class AttendanceMonthCalendar implements AttendanceCycle
{
    public function __construct(private readonly PayCycle $cycle) {}

    public function window(int $companyId, int $year, int $month): array
    {
        $period = $this->period($companyId, $year, $month);
        if ($period?->isFixed()) {
            return [CarbonImmutable::parse($period->start_date)->startOfDay(), CarbonImmutable::parse($period->end_date)->startOfDay()];
        }

        return $this->cycle->bounds($companyId, $year, $month);
    }

    public function monthOf(int $companyId, CarbonInterface $date): array
    {
        return $this->cycle->monthContaining($companyId, $date);
    }

    public function isFinal(int $companyId, int $year, int $month): bool
    {
        $period = $this->period($companyId, $year, $month);

        return $period !== null && ($period->status === 'closed'
            || PayrollRun::query()->where('payroll_period_id', $period->id)->whereIn('status', ['finalized', 'paid'])->exists());
    }

    private function period(int $companyId, int $year, int $month): ?PayrollPeriod
    {
        return PayrollPeriod::query()->where('company_id', $companyId)->where('year', $year)->where('month', $month)->first();
    }
}
