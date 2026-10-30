<?php

namespace App\Domain\Expense\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The days an expense limit or claim frequency counts over (peopleos.expense.periods), around an expense date. Weeks run
 * Monday to Sunday; months are calendar months; quarters, half-years and years count from expense.year_start_month. A
 * per-claim limit has no window (each claim on its own); a per-trip one is the trip's dates (EX-F), else per claim.
 */
final class ExpensePeriods
{
    /**
     * @param  array{0: CarbonInterface|string, 1: CarbonInterface|string}|null  $trip
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public static function window(string $period, CarbonInterface|string $date, int $yearStartMonth = 1, ?array $trip = null): ?array
    {
        $day = CarbonImmutable::parse($date)->startOfDay();
        $yearStartMonth = min(12, max(1, $yearStartMonth));

        return match ($period) {
            'day' => [$day, $day],
            'week' => [$day->startOfWeek(CarbonInterface::MONDAY), $day->startOfWeek(CarbonInterface::MONDAY)->addDays(6)],
            'month' => [$day->startOfMonth(), $day->endOfMonth()->startOfDay()],
            'quarter' => self::block($day, $yearStartMonth, 3),
            'half_year' => self::block($day, $yearStartMonth, 6),
            'year' => self::block($day, $yearStartMonth, 12),
            'trip' => $trip !== null ? [CarbonImmutable::parse($trip[0])->startOfDay(), CarbonImmutable::parse($trip[1])->startOfDay()] : null,
            default => null,
        };
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private static function block(CarbonImmutable $day, int $yearStartMonth, int $months): array
    {
        $offset = ($day->month - $yearStartMonth + 12) % 12;
        $start = $day->startOfMonth()->subMonthsNoOverflow($offset - intdiv($offset, $months) * $months);

        return [$start, $start->addMonthsNoOverflow($months)->subDay()];
    }
}
