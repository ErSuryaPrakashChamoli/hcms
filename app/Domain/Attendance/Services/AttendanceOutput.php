<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Models\AttendancePenalty;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Support\AttendanceDay;
use App\Domain\Attendance\Support\RequestRules;
use App\Domain\Configuration\Services\PolicyResolver;
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
        $from = Carbon::parse($from)->toDateString();
        $to = Carbon::parse($to)->toDateString();
        // HP-1: applied late-mark penalties, as days per date.
        $penalties = AttendancePenalty::query()->where('employee_id', $employee->getKey())->where('status', 'applied')
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->get(['date', 'days'])
            ->groupBy(fn (AttendancePenalty $p) => $p->date->toDateString())->map(fn ($rows) => min(1.0, (float) $rows->sum('days')));

        return AttendanceRecord::query()
            ->where('employee_id', $employee->getKey())
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->orderBy('date')
            ->get()
            ->map(fn (AttendanceRecord $r) => AttendanceDay::fromRecord($r, (float) ($penalties[$r->date->toDateString()] ?? 0)));
    }

    /**
     * HROne parity PO-3: the dates that have a processed attendance record, per employee, in [from, to] — in one query per
     * 500 employees, for pre-run readiness counts (quantities stay with forEmployee()). The reader's access scope applies.
     *
     * @param  list<int>  $employeeIds
     * @return array<int, list<string>> employee id => dates (Y-m-d)
     */
    public function processedDates(array $employeeIds, Carbon|string $from, Carbon|string $to): array
    {
        $employeeIds = array_values(array_unique(array_map('intval', $employeeIds)));
        $out = [];
        foreach (array_chunk($employeeIds, 500) as $chunk) {
            foreach (AttendanceRecord::query()->whereIn('employee_id', $chunk)
                ->whereDate('date', '>=', Carbon::parse($from)->toDateString())->whereDate('date', '<=', Carbon::parse($to)->toDateString())
                ->toBase()->get(['employee_id', 'date']) as $row) {
                $out[(int) $row->employee_id][substr((string) $row->date, 0, 10)] = true;
            }
        }

        return array_map(fn (array $dates) => array_keys($dates), $out);
    }

    /**
     * HROne setup parity (TO-5): days that count for the shift allowance — days present (half days count half) on shifts that
     * earn it; days worked on weekly offs and holidays only when the attendance policy allows it and the day reached its
     * minimum minutes.
     */
    public function shiftAllowanceDays(Employee $employee, Carbon|string $from, Carbon|string $to): float
    {
        $policy = app(PolicyResolver::class)->resolve('attendance', $employee, Carbon::parse($to));
        $offDays = RequestRules::isYes($policy?->setting('shift_allowance_on_off_days'));
        $offMinimum = (int) ($policy?->setting('shift_allowance_off_day_minutes') ?? 0);

        return (float) AttendanceRecord::query()->where('employee_id', $employee->id)
            ->whereDate('date', '>=', Carbon::parse($from)->toDateString())->whereDate('date', '<=', Carbon::parse($to)->toDateString())
            ->whereHas('shift', fn ($q) => $q->where('allowance_eligible', true))->get(['status', 'exceptions', 'worked_minutes'])
            ->sum(function (AttendanceRecord $r) use ($offDays, $offMinimum) {
                $offDayWork = in_array('holiday_work', $r->exceptions ?? [], true);
                if ($offDayWork) {
                    return $offDays && (int) $r->worked_minutes >= $offMinimum ? 1.0 : 0.0;
                }

                return match ($r->status) {
                    'present', 'on_duty' => 1.0, 'half_day' => 0.5, default => 0.0,
                };
            });
    }

    /** @return array{days: int, paid_days: int, half_days: int, absent_days: int, worked_minutes: int, scheduled_minutes: int, late_minutes: int, early_minutes: int, approved_overtime_minutes: int, unprocessed_days: int, penalty_days: float} */
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
            'penalty_days' => (float) $days->sum('penaltyDays'),
        ];
    }
}
