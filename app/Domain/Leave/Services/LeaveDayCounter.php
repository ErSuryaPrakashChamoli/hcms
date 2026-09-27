<?php

namespace App\Domain\Leave\Services;

use App\Domain\Attendance\Services\HolidayResolver;
use App\Domain\Attendance\Services\ShiftResolver;
use App\Domain\Employment\Models\Employee;
use Illuminate\Support\Carbon;

/** Turns a span (with half-day sessions) into the dated list of leave days, skipping offs/holidays as configured. */
final class LeaveDayCounter
{
    public function __construct(
        private readonly ShiftResolver $shifts,
        private readonly HolidayResolver $holidays,
        private readonly LeaveEntitlements $entitlements,
    ) {}

    /**
     * @return list<array{date: string, session: string, days: float}>
     */
    public function dates(Employee $employee, Carbon $from, Carbon $to, string $fromSession = 'full', string $toSession = 'full'): array
    {
        $countOffs = $this->entitlements->countsWeeklyOffs($employee, $from);
        $countHolidays = $this->entitlements->countsHolidays($employee, $from);
        $dates = [];

        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
            if (! $countOffs && $this->shifts->resolve($employee, $day)['weekly_off']) {
                continue;
            }

            if (! $countHolidays && $this->holidays->holidayOn($employee, $day) !== null) {
                continue;
            }

            $session = 'full';

            if ($day->isSameDay($from) && $fromSession !== 'full') {
                $session = $fromSession;
            } elseif ($day->isSameDay($to) && $toSession !== 'full') {
                $session = $toSession;
            }

            if ($from->isSameDay($to) && $fromSession !== 'full') {
                $session = $fromSession;
            }

            $dates[] = ['date' => $day->toDateString(), 'session' => $session, 'days' => $session === 'full' ? 1.0 : 0.5];
        }

        return $dates;
    }

    /** @param  list<array{days: float}>  $dates */
    public function total(array $dates): float
    {
        return round(array_sum(array_column($dates, 'days')), 2);
    }
}
