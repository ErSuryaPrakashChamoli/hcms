<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Attendance\Models\AttendancePenalty;
use Illuminate\Database\Eloquent\Builder;

/** HROne parity HP-1: late-mark penalties (attendance penalty detail, late penalty by month). */
class AttendancePenaltiesDataset extends Dataset
{
    public function key(): string
    {
        return 'attendance_penalties';
    }

    public function label(): string
    {
        return 'Late-mark penalties';
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
        return [
            'date' => ['label' => 'Date', 'type' => 'date', 'value' => fn ($r) => $r->date],
            'month' => ['label' => 'Month', 'type' => 'string', 'value' => fn ($r) => $r->month],
            ...$this->employeeFields(fn ($r) => $r->employee),
            'late_count' => ['label' => 'Late marks counted', 'type' => 'number', 'value' => fn ($r) => $r->late_count],
            'action' => ['label' => 'Action', 'type' => 'string', 'value' => fn ($r) => AttendancePenalty::ACTIONS[$r->action] ?? $r->action],
            'days' => ['label' => 'Loss-of-pay days', 'type' => 'number', 'value' => fn ($r) => $r->status === 'applied' ? (float) $r->days : 0.0],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($r) => $r->status],
            'waived_by' => ['label' => 'Waived by', 'type' => 'string', 'value' => fn ($r) => $r->waiver?->name],
            'waive_reason' => ['label' => 'Waiver reason', 'type' => 'string', 'value' => fn ($r) => $r->waive_reason],
            'count' => ['label' => 'Penalties (1 per row)', 'type' => 'number', 'value' => fn ($r) => 1],
        ];
    }

    public function query(): Builder
    {
        return AttendancePenalty::query()->with([...self::employeeWith('employee'), 'waiver'])->orderByDesc('date');
    }
}
