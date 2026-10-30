<?php

namespace App\Domain\Expense\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Expense\Models\TravelRequest;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Platform\Services\SettingsRepository;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * HROne setup parity EX-F (Show travel on the attendance calendar): the days an employee is on an approved trip, for a
 * read-only mark on the attendance calendar when expense.travel.on_attendance_calendar is on. Attendance itself is not
 * changed: on-duty requests still decide the day's status.
 */
final class TravelCalendar
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('expense.travel.on_attendance_calendar', false);
    }

    /**
     * @return array<string, string> Y-m-d => "TRV-2026-00001 · Mumbai" for each day of an approved trip in [from, to]; empty when switched off
     */
    public function days(Employee $employee, CarbonInterface $from, CarbonInterface $to): array
    {
        if (! $this->enabled()) {
            return [];
        }
        $days = [];
        TravelRequest::query()->withoutGlobalScope(AccessScope::class)->with('legs')->where('employee_id', $employee->id)->whereIn('status', TravelRequest::APPROVED)
            ->whereDate('start_date', '<=', $to->toDateString())->whereDate('end_date', '>=', $from->toDateString())->orderBy('start_date')->get()
            ->each(function (TravelRequest $trip) use (&$days, $from, $to) {
                $label = $trip->number.(($places = $trip->destinations()) !== '' ? ' · '.$places : '');
                $day = CarbonImmutable::parse(max($trip->start_date->toDateString(), $from->toDateString()));
                $last = CarbonImmutable::parse(min($trip->end_date->toDateString(), $to->toDateString()));
                for (; $day->lte($last); $day = $day->addDay()) {
                    $days[$day->toDateString()] ??= $label;
                }
            });

        return $days;
    }
}
