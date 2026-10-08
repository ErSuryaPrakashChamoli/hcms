<?php

namespace App\Domain\ServiceDesk\Support;

use App\Domain\ServiceDesk\Contracts\SlaCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Phase 12: every minute counts (24 × 7) — the legacy ticket-category SLA and "calendar" policies. */
final class CalendarHoursCalendar implements SlaCalendar
{
    public function addMinutes(CarbonInterface $from, int $minutes): CarbonImmutable
    {
        return CarbonImmutable::instance($from)->addMinutes(max(0, $minutes));
    }

    public function minutesBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        return $to->greaterThan($from) ? (int) floor($from->diffInMinutes($to, true)) : 0;
    }

    public function describe(): array
    {
        return ['mode' => 'calendar', 'timezone' => 'UTC', 'holiday_calendar_id' => null];
    }
}
