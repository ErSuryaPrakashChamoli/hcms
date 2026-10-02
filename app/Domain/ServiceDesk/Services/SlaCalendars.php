<?php

namespace App\Domain\ServiceDesk\Services;

use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Services\AttendanceTimezone;
use App\Domain\Attendance\Services\HolidayResolver;
use App\Domain\Employment\Models\Employee;
use App\Domain\ServiceDesk\Contracts\SlaCalendar;
use App\Domain\ServiceDesk\Models\ServiceSlaPolicy;
use App\Domain\ServiceDesk\Support\BusinessHoursCalendar;
use App\Domain\ServiceDesk\Support\CalendarHoursCalendar;
use Illuminate\Support\Carbon;

/**
 * Phase 12: builds the SLA calendar for a request from what PeopleOS already knows:
 * - the employee's attendance holiday calendar (HolidayResolver; public and company holidays stop the
 *   clock, optional holidays do not);
 * - the work location's timezone (AttendanceTimezone);
 * - the service hours from the SLA policy, else `peopleos.servicedesk.business_hours`.
 *
 * Holidays are loaded once per calendar for the horizon. There is no second holiday calendar.
 */
final class SlaCalendars
{
    /** @var array<int, array<string, bool>> */
    private array $holidayCache = [];

    public function __construct(private readonly HolidayResolver $holidays, private readonly AttendanceTimezone $timezones) {}

    public function for(?Employee $employee, ?ServiceSlaPolicy $policy, ?string $mode = null): SlaCalendar
    {
        $mode ??= $policy?->calendar ?? 'calendar';
        if ($mode !== 'business') {
            return new CalendarHoursCalendar;
        }
        $hours = ($policy?->business_hours ?: null) ?? config('peopleos.servicedesk.business_hours');
        $today = Carbon::now();
        $timezone = $employee ? $this->timezones->for($employee, null, $today) : config('app.timezone', 'UTC');
        $calendar = $employee ? $this->holidays->calendarFor($employee, $today) : null;

        return new BusinessHoursCalendar(
            $timezone,
            array_map('intval', $hours['days'] ?? [1, 2, 3, 4, 5]),
            (string) ($hours['start'] ?? '09:00'),
            (string) ($hours['end'] ?? '18:00'),
            $calendar ? $this->holidayDates($calendar->id) : [],
            $calendar?->id,
        );
    }

    /** @return array<string, bool> Y-m-d => is_half_day, for public and company holidays */
    private function holidayDates(int $calendarId): array
    {
        return $this->holidayCache[$calendarId] ??= Holiday::query()->where('holiday_calendar_id', $calendarId)
            ->whereIn('type', ['public', 'company'])
            ->whereBetween('date', [Carbon::now()->subDays(60)->toDateString(), Carbon::now()->addDays(800)->toDateString()])
            ->get(['date', 'is_half_day'])
            ->mapWithKeys(fn (Holiday $h) => [Carbon::parse($h->date)->toDateString() => (bool) $h->is_half_day])
            ->all();
    }
}
