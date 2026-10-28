<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Attendance\Models\WorkScheduleAssignment;
use Illuminate\Database\Eloquent\Builder;

class ShiftRosterDataset extends Dataset
{
    public function key(): string
    {
        return 'shift_roster';
    }

    public function label(): string
    {
        return 'Work schedule and shift roster assignments';
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
            ...$this->employeeFields(fn ($a) => $a->employee),
            'schedule' => ['label' => 'Work schedule', 'type' => 'string', 'value' => fn ($a) => $a->schedule?->name],
            'schedule_code' => ['label' => 'Schedule code', 'type' => 'string', 'value' => fn ($a) => $a->schedule?->code],
            'effective_from' => ['label' => 'From', 'type' => 'date', 'value' => fn ($a) => $a->effective_from],
            'effective_to' => ['label' => 'To', 'type' => 'date', 'value' => fn ($a) => $a->effective_to],
            'is_current' => ['label' => 'Current', 'type' => 'boolean', 'value' => fn ($a) => $a->effective_from?->lte(today()) && ($a->effective_to === null || $a->effective_to->gte(today()))],
            'reason' => ['label' => 'Reason', 'type' => 'string', 'value' => fn ($a) => $a->reason],
        ];
    }

    public function query(): Builder
    {
        return WorkScheduleAssignment::query()->with([...self::employeeWith('employee'), 'schedule'])->orderByDesc('effective_from');
    }
}
