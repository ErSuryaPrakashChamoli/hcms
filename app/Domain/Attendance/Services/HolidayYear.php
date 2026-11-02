<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Models\EmployeeHolidayChange;
use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\HolidayCalendar;
use App\Domain\Attendance\Models\TimeOfficeRequest;
use App\Domain\Employment\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * HROne parity MS-12 (My space → Holiday calendar): an employee's holidays of a calendar year, from the calendar that
 * applies to them (HolidayResolver::calendarFor, as attendance itself resolves it) — fixed holidays and, on a calendar
 * whose optional holidays must be chosen, the restricted holidays with the employee's choice (chosen = an approved
 * restricted-holiday request, requested = one waiting), the year's quota and the calendar's note for employees. On a
 * calendar whose optional holidays need no choosing they are holidays for everyone and are listed with the fixed ones.
 * Reads only; quantities and dates, nothing else.
 */
final class HolidayYear
{
    public function __construct(private readonly HolidayResolver $resolver, private readonly TimeOfficeRequests $requests) {}

    /**
     * Calendar years worth offering: last year, this year and next year, plus any year the employee's calendar has holidays in.
     *
     * @return list<int> newest first
     */
    public function years(Employee $employee): array
    {
        $now = (int) now()->year;
        $calendar = $this->resolver->calendarFor($employee, now());
        $years = $calendar === null ? [] : Holiday::query()->where('holiday_calendar_id', $calendar->id)->pluck('date')
            ->map(fn ($date) => (int) Carbon::parse($date)->year)->all();
        // HROne parity TX-12: years in which HR changed the employee's holidays.
        $changed = EmployeeHolidayChange::query()->where('employee_id', $employee->id)->live()->pluck('date')
            ->map(fn ($date) => (int) Carbon::parse($date)->year)->all();

        return collect([$now - 1, $now, $now + 1, ...$years, ...$changed])->unique()->sortDesc()->values()->all();
    }

    /**
     * @return array{year: int, calendar: ?array{name: string, note: ?string, requires_selection: bool}, fixed: list<array<string, mixed>>, restricted: list<array<string, mixed>>, quota: ?array{taken: int, quota: int, year: string}}
     */
    public function for(Employee $employee, int $year): array
    {
        $calendar = $this->resolver->calendarFor($employee, $this->referenceDate($year));
        // HROne parity TX-12: HR's changes of the employee's holidays in the year (none by default).
        $changes = app(EmployeeHolidayChanges::class)->liveBetween($employee, Carbon::create($year, 1, 1), Carbon::create($year, 12, 31));
        if (! $calendar instanceof HolidayCalendar) {
            return $this->withChanges(['year' => $year, 'calendar' => null, 'fixed' => [], 'restricted' => [], 'quota' => null], $changes);
        }

        $selecting = (bool) $calendar->optional_requires_selection;
        $holidays = Holiday::query()->where('holiday_calendar_id', $calendar->id)
            ->whereDate('date', '>=', sprintf('%04d-01-01', $year))->whereDate('date', '<=', sprintf('%04d-12-31', $year))
            ->orderBy('date')->get();
        $choices = $selecting ? TimeOfficeRequest::query()->where('employee_id', $employee->id)->where('type', 'restricted_holiday')
            ->whereIn('holiday_id', $holidays->pluck('id')->all())->whereIn('status', [...TimeOfficeRequest::EFFECTIVE, 'pending'])
            ->get(['holiday_id', 'status'])->groupBy('holiday_id') : collect();
        $today = now()->startOfDay();

        $row = function (Holiday $h) use ($choices, $selecting, $today) {
            $restricted = $selecting && $h->type === 'optional';
            $statuses = $restricted ? $choices->get($h->id, collect())->pluck('status')->all() : [];

            return [
                'id' => (int) $h->id, 'name' => (string) $h->name, 'date' => $h->date->copy(), 'weekday' => $h->date->format('l'),
                'type' => config("peopleos.attendance.holiday_types.{$h->type}", ucfirst((string) $h->type)), 'half_day' => (bool) $h->is_half_day,
                'past' => $h->date->lt($today),
                'choice' => ! $restricted ? null : (array_intersect($statuses, TimeOfficeRequest::EFFECTIVE) !== [] ? 'chosen' : (in_array('pending', $statuses, true) ? 'requested' : 'not_chosen')),
            ];
        };
        $restricted = $holidays->filter(fn (Holiday $h) => $selecting && $h->type === 'optional');
        $first = $restricted->first();

        return $this->withChanges([
            'year' => $year,
            'calendar' => ['name' => (string) $calendar->name, 'note' => filled($calendar->employee_note) ? (string) $calendar->employee_note : null, 'requires_selection' => $selecting],
            'fixed' => $holidays->reject(fn (Holiday $h) => $selecting && $h->type === 'optional')->map($row)->values()->all(),
            'restricted' => $restricted->map($row)->values()->all(),
            'quota' => $first ? $this->requests->restrictedHolidayQuota($employee, $first->setRelation('calendar', $calendar)) : null,
        ], $changes);
    }

    /**
     * HROne parity TX-12: the year with HR's changes of the employee's holidays — a holiday taken away leaves the lists (it
     * is listed under `removed`), a holiday given joins the fixed list, or the restricted list when it is a restricted
     * holiday (choice `given`: it is the employee's without a request). Without changes the year is returned as it was,
     * with an empty `removed`.
     *
     * @param  array<string, mixed>  $year
     * @param  Collection<string, EmployeeHolidayChange>  $changes  Y-m-d => change
     * @return array<string, mixed>
     */
    private function withChanges(array $year, Collection $changes): array
    {
        $year['removed'] = [];
        if ($changes->isEmpty()) {
            return $year;
        }
        $today = now()->startOfDay();
        $removed = $changes->reject(fn (EmployeeHolidayChange $c) => $c->adds())->keys()->all();
        $keep = fn (array $rows) => array_values(array_filter($rows, fn (array $r) => ! in_array($r['date']->toDateString(), $removed, true)));
        $year['fixed'] = $keep($year['fixed']);
        $year['restricted'] = $keep($year['restricted']);

        foreach ($changes as $change) {
            $row = [
                'id' => null, 'change_id' => (int) $change->id, 'name' => (string) $change->name, 'date' => $change->date->copy(), 'weekday' => $change->date->format('l'),
                'type' => $change->typeLabel(), 'half_day' => false, 'past' => $change->date->lt($today),
            ];
            if (! $change->adds()) {
                $year['removed'][] = $row + ['note' => 'Not a holiday for you (changed by HR'.($change->action === 'replace' ? ', replaced' : '').')'];
            } elseif ($change->is_restricted) {
                $year['restricted'][] = $row + ['choice' => 'given', 'note' => 'Given to you by HR'];
            } else {
                $year['fixed'][] = $row + ['choice' => null, 'note' => 'Given to you by HR'];
            }
        }
        foreach (['fixed', 'restricted', 'removed'] as $list) {
            usort($year[$list], fn (array $a, array $b) => $a['date'] <=> $b['date']);
        }

        return $year;
    }

    /** The date the calendar is resolved on: today in the current year, else the year's last day (past) or first day (future). */
    private function referenceDate(int $year): Carbon
    {
        $now = now();

        return match (true) {
            $year === (int) $now->year => $now->copy()->startOfDay(),
            $year < (int) $now->year => Carbon::create($year, 12, 31),
            default => Carbon::create($year, 1, 1),
        };
    }
}
