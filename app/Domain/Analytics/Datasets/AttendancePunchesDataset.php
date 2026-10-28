<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Attendance\Models\AttendancePunch;
use Illuminate\Database\Eloquent\Builder;

class AttendancePunchesDataset extends Dataset
{
    public function key(): string
    {
        return 'attendance_punches';
    }

    public function label(): string
    {
        return 'Punch log (every raw punch from devices, mobile, web and uploads)';
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
            'date' => ['label' => 'Date', 'type' => 'date', 'value' => fn ($p) => $p->punched_at],
            'time' => ['label' => 'Time', 'type' => 'string', 'value' => fn ($p) => $p->punched_at?->format('H:i:s')],
            'month' => ['label' => 'Month', 'type' => 'string', 'value' => fn ($p) => $p->punched_at?->format('Y-m')],
            ...$this->employeeFields(fn ($p) => $p->employee),
            'direction' => ['label' => 'Direction', 'type' => 'string', 'value' => fn ($p) => $p->direction],
            'source' => ['label' => 'Source', 'type' => 'string', 'value' => fn ($p) => config('peopleos.attendance.sources.'.$p->source, $p->source)],
            'device' => ['label' => 'Device', 'type' => 'string', 'value' => fn ($p) => $p->device?->name],
            'geo_tagged' => ['label' => 'Has location', 'type' => 'boolean', 'value' => fn ($p) => $p->latitude !== null && $p->longitude !== null],
            'processing_status' => ['label' => 'Processing status', 'type' => 'string', 'value' => fn ($p) => $p->processing_status],
            'processing_error' => ['label' => 'Processing error', 'type' => 'string', 'value' => fn ($p) => $p->processing_error],
            'recorded_by' => ['label' => 'Recorded by', 'type' => 'string', 'value' => fn ($p) => $p->recorder?->name],
            'note' => ['label' => 'Note', 'type' => 'string', 'value' => fn ($p) => $p->note],
            'count' => ['label' => 'Punches (1 per row)', 'type' => 'number', 'value' => fn ($p) => 1],
        ];
    }

    public function query(): Builder
    {
        return AttendancePunch::query()->with([...self::employeeWith('employee'), 'device', 'recorder'])->orderByDesc('punched_at');
    }
}
