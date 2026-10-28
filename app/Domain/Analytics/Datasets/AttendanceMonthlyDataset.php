<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Attendance\Models\AttendanceRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Processed attendance rolled up per employee and calendar month in SQL (one row each), so a month of a
 * large workforce stays well inside analytics.max_rows. Statuses are counted as recorded; payable days
 * are the payroll dataset's paid days.
 */
class AttendanceMonthlyDataset extends Dataset
{
    /** column => statuses counted in it */
    private const COUNTS = [
        'present_days' => ['present'], 'half_days' => ['half_day'], 'absent_days' => ['absent'], 'missed_punch_days' => ['incomplete'],
        'leave_days' => ['leave'], 'unpaid_leave_days' => ['unpaid_leave'], 'weekly_offs' => ['weekly_off'], 'holidays' => ['holiday'],
        'wfh_days' => ['wfh'], 'on_duty_days' => ['on_duty', 'field_duty'], 'not_processed_days' => ['not_processed'],
    ];

    public function key(): string
    {
        return 'attendance_monthly';
    }

    public function label(): string
    {
        return 'Monthly attendance summary (one row per employee per month)';
    }

    public function category(): string
    {
        return 'Attendance';
    }

    public function permissions(): array
    {
        return ['attendance.view'];
    }

    public function fields(): array
    {
        $int = fn (string $column) => fn ($r) => (int) $r->{$column};
        $labels = [
            'present_days' => 'Present', 'half_days' => 'Half days', 'absent_days' => 'Absent', 'missed_punch_days' => 'Missed punch (incomplete)',
            'leave_days' => 'On leave (paid)', 'unpaid_leave_days' => 'Leave without pay', 'weekly_offs' => 'Weekly offs', 'holidays' => 'Holidays',
            'wfh_days' => 'Work from home', 'on_duty_days' => 'On duty / field duty', 'not_processed_days' => 'Not processed',
        ];
        $counts = [];
        foreach ($labels as $column => $label) {
            $counts[$column] = ['label' => $label, 'type' => 'number', 'value' => $int($column)];
        }

        return [
            'month' => ['label' => 'Month', 'type' => 'string', 'value' => fn ($r) => $r->month],
            'month_start' => ['label' => 'Month (date)', 'type' => 'date', 'value' => fn ($r) => $r->month ? Carbon::parse($r->month.'-01') : null],
            ...$this->employeeFields(fn ($r) => $r->employee),
            'days_recorded' => ['label' => 'Days recorded', 'type' => 'number', 'value' => $int('days_recorded')],
            ...$counts,
            'days_worked' => ['label' => 'Days worked (half day = ½)', 'type' => 'number', 'value' => fn ($r) => (int) $r->present_days + (int) $r->wfh_days + (int) $r->on_duty_days + 0.5 * (int) $r->half_days],
            'late_count' => ['label' => 'Late arrivals', 'type' => 'number', 'value' => $int('late_count')],
            'late_minutes' => ['label' => 'Late (minutes)', 'type' => 'number', 'value' => $int('late_minutes')],
            'early_leave_count' => ['label' => 'Early departures', 'type' => 'number', 'value' => $int('early_leave_count')],
            'early_leave_minutes' => ['label' => 'Early leaving (minutes)', 'type' => 'number', 'value' => $int('early_leave_minutes')],
            'worked_hours' => ['label' => 'Worked hours', 'type' => 'number', 'value' => fn ($r) => round((int) $r->worked_minutes / 60, 2)],
            'overtime_hours' => ['label' => 'Overtime hours (approved)', 'type' => 'number', 'value' => fn ($r) => round((int) $r->overtime_minutes / 60, 2)],
            'regularised_days' => ['label' => 'Regularised days', 'type' => 'number', 'value' => $int('regularised_days')],
        ];
    }

    public function query(): Builder
    {
        $month = 'substr(date, 1, 7)';
        $select = ["employee_id, {$month} as month, count(*) as days_recorded"];
        $bindings = [];
        foreach (self::COUNTS as $column => $statuses) {
            $select[] = 'sum(case when status in ('.implode(', ', array_fill(0, count($statuses), '?')).") then 1 else 0 end) as {$column}";
            array_push($bindings, ...$statuses);
        }
        $select[] = 'sum(case when late_minutes > 0 then 1 else 0 end) as late_count, sum(late_minutes) as late_minutes';
        $select[] = 'sum(case when early_leave_minutes > 0 then 1 else 0 end) as early_leave_count, sum(early_leave_minutes) as early_leave_minutes';
        $select[] = 'sum(worked_minutes) as worked_minutes, sum(overtime_approved_minutes) as overtime_minutes, sum(case when is_regularised then 1 else 0 end) as regularised_days';

        return AttendanceRecord::query()->selectRaw(implode(', ', $select), $bindings)
            ->groupBy('employee_id')->groupByRaw($month)
            ->orderByRaw("{$month} desc")->orderBy('employee_id')
            ->with(self::employeeWith('employee'));
    }
}
