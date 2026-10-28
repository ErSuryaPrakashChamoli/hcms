<?php

namespace App\Domain\ComplianceReports\Builders;

use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Services\HolidayResolver;
use App\Domain\ComplianceReports\Support\ReportContext;
use App\Domain\Employment\Models\Employee;
use Illuminate\Support\Carbon;

/**
 * List of national, festival and other paid holidays for the year, as displayed at the establishment: the holidays of
 * every holiday calendar its employees follow (resolved once per work location), falling in the period.
 */
final class HolidayList extends Builder
{
    public function build(ReportContext $context): array
    {
        $period = $context->period;
        $resolver = app(HolidayResolver::class);
        $employees = $context->data->employees($context->company, $period->start, $period->end, $context->establishment);

        $calendars = $employees
            ->groupBy(fn (Employee $e) => (int) $context->data->positionOn($e, $period->end)?->location_id)
            ->map(fn ($group) => $resolver->calendarFor($group->first(), Carbon::parse($period->end)))
            ->filter()->unique('id');

        $rows = Holiday::query()->with('calendar')->whereIn('holiday_calendar_id', $calendars->pluck('id'))
            ->whereDate('date', '>=', $period->start->toDateString())->whereDate('date', '<=', $period->end->toDateString())
            ->orderBy('date')->get()
            ->map(fn (Holiday $h) => [
                'date' => $this->date($h->date),
                'day' => $h->date->format('l'),
                'name' => $h->name,
                'type' => (string) config("peopleos.attendance.holiday_types.{$h->type}", $h->type).($h->is_half_day ? ' (half day)' : ''),
                'calendar' => $h->calendar?->name,
            ])->values()->all();

        return [$this->sheet($context, ['sl' => 'Sl.', 'date' => 'Date', 'day' => 'Day', 'name' => 'Holiday', 'type' => 'Type', 'calendar' => 'Holiday calendar'], $this->numbered($rows), [
            'message' => $calendars->isEmpty() ? 'No holiday calendar applies to the employees of this establishment.' : 'No holidays in the period.',
            'notes' => ['Display the notice of holidays at the establishment and send it to the authority where the state rules require it.'],
        ])];
    }
}
