<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Attendance\Models\Holiday;
use Illuminate\Database\Eloquent\Builder;

class HolidaysDataset extends Dataset
{
    public function key(): string
    {
        return 'holidays';
    }

    public function label(): string
    {
        return 'Holiday calendars (holiday list)';
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
            'calendar' => ['label' => 'Calendar', 'type' => 'string', 'value' => fn ($h) => $h->calendar?->name],
            'year' => ['label' => 'Year', 'type' => 'number', 'value' => fn ($h) => $h->date?->year],
            'date' => ['label' => 'Date', 'type' => 'date', 'value' => fn ($h) => $h->date],
            'day' => ['label' => 'Day', 'type' => 'string', 'value' => fn ($h) => $h->date?->format('l')],
            'month' => ['label' => 'Month', 'type' => 'string', 'value' => fn ($h) => $h->date?->format('Y-m')],
            'name' => ['label' => 'Holiday', 'type' => 'string', 'value' => fn ($h) => $h->name],
            'type' => ['label' => 'Type', 'type' => 'string', 'value' => fn ($h) => config('peopleos.attendance.holiday_types.'.$h->type, $h->type)],
            'is_half_day' => ['label' => 'Half day', 'type' => 'boolean', 'value' => fn ($h) => (bool) $h->is_half_day],
            'count' => ['label' => 'Holidays (1 per row)', 'type' => 'number', 'value' => fn ($h) => 1],
        ];
    }

    public function query(): Builder
    {
        return Holiday::query()->with('calendar')->orderBy('date');
    }
}
