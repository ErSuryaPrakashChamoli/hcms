<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Attendance\Models\ShiftOverride;
use App\Domain\Attendance\Services\RosterGrid;
use Illuminate\Database\Eloquent\Builder;

/**
 * HROne parity TX-5 (matrix 24 §24.2.1 #12, the Shift rosters "Reports" tab; matrix 21 §21.2.2 Time Office #36 "Monthly
 * shift roster", shared with AN-28): the day-level roster — one row per rostered day (a shift or a weekly off over the work
 * schedule, from HR, an approved shift change request, the roster board or an upload) with the shift's code, name and
 * times, automatic shift correction, source, reason, who changed it and when. attendance.view, inside the reader's access
 * scope (the rostered days' own access scope); not readable without a signed-in user (not on
 * analytics.unattended_datasets). Days that simply follow the work schedule are the `shift_roster` dataset's assignments.
 */
class DayRosterDataset extends Dataset
{
    public function key(): string
    {
        return 'day_roster';
    }

    public function label(): string
    {
        return 'Day roster (rostered days: shift or weekly off per employee and date)';
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
            ...$this->employeeFields(fn (ShiftOverride $o) => $o->employee),
            'date' => ['label' => 'Attendance date', 'type' => 'date', 'value' => fn (ShiftOverride $o) => $o->date],
            'day' => ['label' => 'Day', 'type' => 'string', 'value' => fn (ShiftOverride $o) => $o->date?->format('D')],
            'month' => ['label' => 'Month', 'type' => 'string', 'value' => fn (ShiftOverride $o) => $o->date?->format('Y-m')],
            'shift_code' => ['label' => 'Shift code', 'type' => 'string', 'value' => fn (ShiftOverride $o) => $o->shift_id === null ? 'WO' : $o->shift?->code],
            'shift' => ['label' => 'Shift', 'type' => 'string', 'value' => fn (ShiftOverride $o) => $o->shift_id === null ? 'Weekly off' : $o->shift?->name],
            'shift_timings' => ['label' => 'Shift timings', 'type' => 'string', 'value' => fn (ShiftOverride $o) => RosterGrid::times($o->shift)],
            'is_weekly_off' => ['label' => 'Weekly off', 'type' => 'boolean', 'value' => fn (ShiftOverride $o) => $o->shift_id === null],
            'auto_shift' => ['label' => 'Automatic shift correction', 'type' => 'boolean', 'value' => fn (ShiftOverride $o) => $o->allowsAutoShift()],
            'source' => ['label' => 'Source', 'type' => 'string', 'value' => fn (ShiftOverride $o) => ShiftOverride::SOURCES[$o->source] ?? $o->source],
            'request' => ['label' => 'Shift change request', 'type' => 'string', 'value' => fn (ShiftOverride $o) => $o->time_office_request_id ? '#'.$o->time_office_request_id : null],
            'reason' => ['label' => 'Reason', 'type' => 'string', 'value' => fn (ShiftOverride $o) => $o->reason],
            'changed_by' => ['label' => 'Changed by', 'type' => 'string', 'value' => fn (ShiftOverride $o) => $o->creator?->name],
            'changed_on' => ['label' => 'Changed on', 'type' => 'date', 'value' => fn (ShiftOverride $o) => $o->updated_at],
        ];
    }

    public function query(): Builder
    {
        return ShiftOverride::query()->with([...self::employeeWith('employee'), 'shift', 'creator'])->orderByDesc('date')->orderBy('employee_id');
    }
}
