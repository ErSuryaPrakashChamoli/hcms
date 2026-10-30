<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Attendance\Models\AttendanceArrear;
use Illuminate\Database\Eloquent\Builder;

/** HROne parity HP-1: attendance arrear days (days corrected after the payroll lock). */
class AttendanceArrearsDataset extends Dataset
{
    public function key(): string
    {
        return 'attendance_arrears';
    }

    public function label(): string
    {
        return 'Attendance arrear days';
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
        $status = fn (string $s) => config("peopleos.attendance.statuses.{$s}", $s);

        return [
            'date' => ['label' => 'Date', 'type' => 'date', 'value' => fn ($r) => $r->date],
            'month' => ['label' => 'Month', 'type' => 'string', 'value' => fn ($r) => $r->date?->format('Y-m')],
            ...$this->employeeFields(fn ($r) => $r->employee),
            'status_before' => ['label' => 'Paid as', 'type' => 'string', 'value' => fn ($r) => $status((string) $r->status_before)],
            'status_after' => ['label' => 'Corrected to', 'type' => 'string', 'value' => fn ($r) => $status((string) $r->status_after)],
            'paid_before' => ['label' => 'Paid days before', 'type' => 'number', 'value' => fn ($r) => (float) $r->paid_before],
            'paid_after' => ['label' => 'Paid days after', 'type' => 'number', 'value' => fn ($r) => (float) $r->paid_after],
            'delta_days' => ['label' => 'Arrear days', 'type' => 'number', 'value' => fn ($r) => (float) $r->delta_days],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($r) => AttendanceArrear::STATUSES[$r->status] ?? $r->status],
            'count' => ['label' => 'Days (1 per row)', 'type' => 'number', 'value' => fn ($r) => 1],
        ];
    }

    public function query(): Builder
    {
        return AttendanceArrear::query()->with(self::employeeWith('employee'))->orderByDesc('date');
    }
}
