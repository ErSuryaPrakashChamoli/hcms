<?php

namespace App\Domain\ServiceDesk\Support;

use App\Domain\ServiceDesk\Contracts\SlaCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Phase 12: service time inside configured service hours on service days, in the given timezone,
 * excluding the holiday dates supplied (from the employee's attendance holiday calendar; a half-day
 * holiday keeps the first half of the day). Pure arithmetic; SlaCalendars resolves the inputs.
 */
final class BusinessHoursCalendar implements SlaCalendar
{
    private const HORIZON_DAYS = 800;

    /**
     * @param  list<int>  $days  ISO weekdays (1 = Monday … 7 = Sunday)
     * @param  array<string, bool>  $holidays  Y-m-d => is_half_day
     */
    public function __construct(
        private readonly string $timezone,
        private readonly array $days,
        private readonly string $start,
        private readonly string $end,
        private readonly array $holidays = [],
        private readonly ?int $holidayCalendarId = null,
    ) {
        if ($this->days === [] || $this->minuteOfDay($this->end) <= $this->minuteOfDay($this->start)) {
            throw new RuntimeException('Service hours need at least one day and an end after the start.');
        }
    }

    public function addMinutes(CarbonInterface $from, int $minutes): CarbonImmutable
    {
        $cursor = CarbonImmutable::instance($from)->setTimezone($this->timezone);
        $remaining = max(0, $minutes);
        for ($i = 0; $i < self::HORIZON_DAYS; $i++) {
            [$open, $close] = $this->window($cursor);
            if ($open !== null) {
                $begin = $cursor->greaterThan($open) ? $cursor : $open;
                if ($begin->lessThan($close)) {
                    $available = (int) floor($begin->diffInMinutes($close, true));
                    if ($remaining <= $available) {
                        return $begin->addMinutes($remaining)->utc();
                    }
                    $remaining -= $available;
                }
            }
            $cursor = $cursor->addDay()->startOfDay();
        }

        throw new RuntimeException('No service hours within the SLA horizon; check the service days and holidays.');
    }

    public function minutesBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        if (! $to->greaterThan($from)) {
            return 0;
        }
        $cursor = CarbonImmutable::instance($from)->setTimezone($this->timezone);
        $stop = CarbonImmutable::instance($to)->setTimezone($this->timezone);
        $total = 0;
        for ($i = 0; $i < self::HORIZON_DAYS && $cursor->lessThan($stop); $i++) {
            [$open, $close] = $this->window($cursor);
            if ($open !== null) {
                $begin = $cursor->greaterThan($open) ? $cursor : $open;
                $finish = $stop->lessThan($close) ? $stop : $close;
                if ($finish->greaterThan($begin)) {
                    $total += (int) floor($begin->diffInMinutes($finish, true));
                }
            }
            $cursor = $cursor->addDay()->startOfDay();
        }

        return $total;
    }

    public function describe(): array
    {
        return ['mode' => 'business', 'timezone' => $this->timezone, 'holiday_calendar_id' => $this->holidayCalendarId];
    }

    /** @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable} the service window of the cursor's local day */
    private function window(CarbonImmutable $cursor): array
    {
        $date = $cursor->toDateString();
        if (! in_array($cursor->isoWeekday(), $this->days, true) || ($this->holidays[$date] ?? null) === false) {
            return [null, null];
        }
        $day = $cursor->startOfDay();
        $open = $day->addMinutes($this->minuteOfDay($this->start));
        $close = $day->addMinutes($this->minuteOfDay($this->end));
        if (($this->holidays[$date] ?? null) === true) {
            $close = $open->addMinutes(intdiv((int) $open->diffInMinutes($close, true), 2));
        }

        return [$open, $close];
    }

    private function minuteOfDay(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time) + [0, 0]);

        return $h * 60 + $m;
    }
}
