<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Support\AttendanceDay;
use App\Domain\Employment\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The payroll-ready contract (Phase 2 §41): quantities per employee per day for a period. Payroll
 * consumes this; Attendance never computes earnings, deductions or statutory amounts.
 */
final class AttendanceOutput
{
    /** @return Collection<int, AttendanceDay> */
    public function forEmployee(Employee $employee, Carbon|string $from, Carbon|string $to): Collection
    {
        return AttendanceRecord::query()
            ->where('employee_id', $employee->getKey())
            ->whereDate('date', '>=', Carbon::parse($from)->toDateString())
            ->whereDate('date', '<=', Carbon::parse($to)->toDateString())
            ->orderBy('date')
            ->get()
            ->map(fn (AttendanceRecord $r) => AttendanceDay::fromRecord($r));
    }

    /** @return array{days: int, paid_days: int, half_days: int, absent_days: int, worked_minutes: int, scheduled_minutes: int, late_minutes: int, early_minutes: int, approved_overtime_minutes: int, unprocessed_days: int} */
    public function summary(Employee $employee, Carbon|string $from, Carbon|string $to): array
    {
        $days = $this->forEmployee($employee, $from, $to);
        $span = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;

        return [
            'days' => $days->count(),
            'paid_days' => $days->where('isPaidDay', true)->count() - $days->where('isHalfDay', true)->count() * 0.5,
            'half_days' => $days->where('isHalfDay', true)->count(),
            'absent_days' => $days->whereIn('status', ['absent', 'unpaid_leave'])->count(),
            'worked_minutes' => (int) $days->sum('workedMinutes'),
            'scheduled_minutes' => (int) $days->sum('scheduledMinutes'),
            'late_minutes' => (int) $days->sum('lateMinutes'),
            'early_minutes' => (int) $days->sum('earlyMinutes'),
            'approved_overtime_minutes' => (int) $days->sum('approvedOvertimeMinutes'),
            'unprocessed_days' => max(0, $span - $days->count()),
        ];
    }
}
