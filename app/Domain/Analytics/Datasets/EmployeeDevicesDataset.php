<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Attendance\Models\EmployeeDevice;
use Illuminate\Database\Eloquent\Builder;

/** HROne parity HP-1: registered check-in devices (HROne "UUID details"). */
class EmployeeDevicesDataset extends Dataset
{
    public function key(): string
    {
        return 'checkin_devices';
    }

    public function label(): string
    {
        return 'Check-in devices';
    }

    public function category(): string
    {
        return 'Attendance';
    }

    public function permissions(): array
    {
        return ['attendance.manage'];
    }

    public function fields(): array
    {
        return [
            ...$this->employeeFields(fn ($r) => $r->employee),
            'device' => ['label' => 'Device', 'type' => 'string', 'value' => fn ($r) => $r->label ?: substr((string) $r->device_identifier, 0, 12).'…'],
            'platform' => ['label' => 'Platform', 'type' => 'string', 'value' => fn ($r) => $r->platform],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($r) => EmployeeDevice::STATUSES[$r->status] ?? $r->status],
            'first_seen' => ['label' => 'First seen', 'type' => 'date', 'value' => fn ($r) => $r->first_seen_at],
            'last_seen' => ['label' => 'Last seen', 'type' => 'date', 'value' => fn ($r) => $r->last_seen_at],
            'reviewed_by' => ['label' => 'Reviewed by', 'type' => 'string', 'value' => fn ($r) => $r->reviewer?->name],
            'count' => ['label' => 'Devices (1 per row)', 'type' => 'number', 'value' => fn ($r) => 1],
        ];
    }

    public function query(): Builder
    {
        return EmployeeDevice::query()->with([...self::employeeWith('employee'), 'reviewer'])->orderByDesc('last_seen_at');
    }
}
