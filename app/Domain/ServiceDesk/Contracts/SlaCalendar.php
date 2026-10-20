<?php

namespace App\Domain\ServiceDesk\Contracts;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Phase 12: the smallest reusable SLA calendar contract — how service time is counted for one request.
 * Business-hours calendars skip weekends (configured service days), the employee's attendance holidays
 * and time outside service hours, in the work location's timezone; calendar-hours calendars count
 * every minute. Built by SlaCalendars; no second holiday calendar exists.
 */
interface SlaCalendar
{
    /** The instant `$minutes` of service time after `$from` (UTC). */
    public function addMinutes(CarbonInterface $from, int $minutes): CarbonImmutable;

    /** Service minutes between two instants (0 when `$to` is not after `$from`). */
    public function minutesBetween(CarbonInterface $from, CarbonInterface $to): int;

    /** @return array{mode: string, timezone: string, holiday_calendar_id: ?int} */
    public function describe(): array;
}
