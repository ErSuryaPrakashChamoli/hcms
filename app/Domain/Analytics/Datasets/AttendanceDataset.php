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

    public function permissions(): array
    {
        return ['attendance.view'];
    }

    public function fields(): array
    {
        return [
            'date' => ['label' => 'Date', 'type' => 'date', 'value' => fn ($r) => $r->date],
            'month' => ['label' => 'Month', 'type' => 'string', 'value' => fn ($r) => $r->date?->format('Y-m')],
            'employee_code' => ['label' => 'Employee code', 'type' => 'string', 'value' => fn ($r) => $r->employee?->employee_code],
            'name' => ['label' => 'Name', 'type' => 'string', 'value' => fn ($r) => $r->employee?->person?->full_name],
            'department' => ['label' => 'Department', 'type' => 'string', 'value' => fn ($r) => $r->employee?->currentPosition?->department?->name],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($r) => $r->status],
            'shift' => ['label' => 'Shift', 'type' => 'string', 'value' => fn ($r) => $r->shift?->name],
            'worked_hours' => ['label' => 'Worked hours', 'type' => 'number', 'value' => fn ($r) => round(($r->worked_minutes ?? 0) / 60, 2)],
            'late_minutes' => ['label' => 'Late (minutes)', 'type' => 'number', 'value' => fn ($r) => (int) $r->late_minutes],
            'overtime_hours' => ['label' => 'Overtime hours', 'type' => 'number', 'value' => fn ($r) => round(($r->overtime_approved_minutes ?? 0) / 60, 2)],
            'is_absent' => ['label' => 'Absent (1/0)', 'type' => 'number', 'value' => fn ($r) => $r->status === 'absent' ? 1 : 0],
            'is_present' => ['label' => 'Present (1/0)', 'type' => 'number', 'value' => fn ($r) => in_array($r->status, ['present', 'half_day', 'wfh', 'on_duty'], true) ? 1 : 0],
            'exceptions' => ['label' => 'Exceptions', 'type' => 'string', 'value' => fn ($r) => implode(', ', $r->exceptions ?? [])],
        ];
    }

    public function query(): Builder
    {
        return AttendanceRecord::query()->with(['employee.person', 'employee.currentPosition.department', 'shift'])->orderByDesc('date');
    }
}
