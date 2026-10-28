<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Attendance\Models\AttendanceRecord;
use Illuminate\Database\Eloquent\Builder;

class AttendanceDataset extends Dataset
{
    public function key(): string
    {
        return 'attendance';
    }

    public function label(): string
    {
        return 'Attendance records (one row per employee per day)';
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
        $exceptions = fn ($r) => $r->exceptions ?? [];

        return [
            'date' => ['label' => 'Date', 'type' => 'date', 'value' => fn ($r) => $r->date],
            'day' => ['label' => 'Day', 'type' => 'string', 'value' => fn ($r) => $r->date?->format('D')],
            'month' => ['label' => 'Month', 'type' => 'string', 'value' => fn ($r) => $r->date?->format('Y-m')],
            ...$this->employeeFields(fn ($r) => $r->employee),
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($r) => $r->status],
            'shift' => ['label' => 'Shift', 'type' => 'string', 'value' => fn ($r) => $r->shift?->name],
            'scheduled_start' => ['label' => 'Shift start', 'type' => 'string', 'value' => fn ($r) => $r->scheduled_start?->format('H:i')],
            'scheduled_end' => ['label' => 'Shift end', 'type' => 'string', 'value' => fn ($r) => $r->scheduled_end?->format('H:i')],
            'first_in' => ['label' => 'First in', 'type' => 'string', 'value' => fn ($r) => $r->first_in?->format('H:i')],
            'last_out' => ['label' => 'Last out', 'type' => 'string', 'value' => fn ($r) => $r->last_out?->format('H:i')],
            'scheduled_hours' => ['label' => 'Scheduled hours', 'type' => 'number', 'value' => fn ($r) => round(($r->scheduled_minutes ?? 0) / 60, 2)],
            'worked_hours' => ['label' => 'Worked hours', 'type' => 'number', 'value' => fn ($r) => round(($r->worked_minutes ?? 0) / 60, 2)],
            'late_minutes' => ['label' => 'Late (minutes)', 'type' => 'number', 'value' => fn ($r) => (int) $r->late_minutes],
            'early_leave_minutes' => ['label' => 'Early leaving (minutes)', 'type' => 'number', 'value' => fn ($r) => (int) $r->early_leave_minutes],
            'is_late' => ['label' => 'Late (1/0)', 'type' => 'number', 'value' => fn ($r) => (int) $r->late_minutes > 0 ? 1 : 0],
            'is_early_leave' => ['label' => 'Left early (1/0)', 'type' => 'number', 'value' => fn ($r) => (int) $r->early_leave_minutes > 0 ? 1 : 0],
            'overtime_claimed_hours' => ['label' => 'Overtime worked (hours)', 'type' => 'number', 'value' => fn ($r) => round(($r->overtime_minutes ?? 0) / 60, 2)],
            'overtime_hours' => ['label' => 'Overtime hours', 'type' => 'number', 'value' => fn ($r) => round(($r->overtime_approved_minutes ?? 0) / 60, 2)],
            'overtime_status' => ['label' => 'Overtime status', 'type' => 'string', 'value' => fn ($r) => $r->overtime_status],
            'is_absent' => ['label' => 'Absent (1/0)', 'type' => 'number', 'value' => fn ($r) => $r->status === 'absent' ? 1 : 0],
            'is_present' => ['label' => 'Present (1/0)', 'type' => 'number', 'value' => fn ($r) => in_array($r->status, ['present', 'half_day', 'wfh', 'on_duty'], true) ? 1 : 0],
            'is_on_leave' => ['label' => 'On leave (1/0)', 'type' => 'number', 'value' => fn ($r) => in_array($r->status, ['leave', 'unpaid_leave'], true) || $r->is_half_day_leave ? 1 : 0],
            'missed_punch' => ['label' => 'Missed punch (1/0)', 'type' => 'number', 'value' => fn ($r) => $r->status === 'incomplete' || array_intersect($exceptions($r), ['missed_punch', 'missing_in', 'missing_out']) !== [] ? 1 : 0],
            'is_regularised' => ['label' => 'Regularised', 'type' => 'boolean', 'value' => fn ($r) => (bool) $r->is_regularised],
            'holiday_name' => ['label' => 'Holiday', 'type' => 'string', 'value' => fn ($r) => $r->holiday_name],
            'exceptions' => ['label' => 'Exceptions', 'type' => 'string', 'value' => fn ($r) => implode(', ', $exceptions($r))],
            'note' => ['label' => 'Note', 'type' => 'string', 'value' => fn ($r) => $r->note],
        ];
    }

    public function query(): Builder
    {
        return AttendanceRecord::query()->with([...self::employeeWith('employee'), 'shift'])->orderByDesc('date');
    }
}
