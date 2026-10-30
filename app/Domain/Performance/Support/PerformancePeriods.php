<?php

namespace App\Domain\Performance\Support;

use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Platform\Services\SettingsRepository;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * HROne setup parity HP-8b: the organisation's performance year (performance.year_start_month; 0 = not set) and the
 * period of a cycle type that contains a day — annual = 12 months from the year start, half-yearly = 6, quarterly = 3.
 */
final class PerformancePeriods
{
    public const MONTHS = ['annual' => 12, 'half_yearly' => 6, 'quarterly' => 3];

    public static function yearStartMonth(): int
    {
        $month = (int) app(SettingsRepository::class)->get('performance.year_start_month', 0);

        return $month >= 1 && $month <= 12 ? $month : 0;
    }

    public static function defaultType(): string
    {
        $type = (string) app(SettingsRepository::class)->get('performance.cycle.default_type', 'annual');

        return array_key_exists($type, (array) config('peopleos.performance.cycle_types')) ? $type : 'annual';
    }

    /** @return array{0: CarbonInterface, 1: CarbonInterface}|null start and end of the period, or null (not set / not a calendar type) */
    public static function containing(string $type, ?CarbonInterface $day = null): ?array
    {
        $startMonth = self::yearStartMonth();
        $months = self::MONTHS[$type] ?? null;
        if ($startMonth === 0 || $months === null) {
            return null;
        }
        $day = Carbon::parse(($day ?? today())->toDateString());
        $yearStart = Carbon::create($day->year, $startMonth, 1);
        if ($yearStart->gt($day)) {
            $yearStart->subYear();
        }
        $elapsed = ($day->year - $yearStart->year) * 12 + ($day->month - $yearStart->month);
        $start = $yearStart->copy()->addMonths(intdiv($elapsed, $months) * $months);

        return [$start, $start->copy()->addMonths($months)->subDay()];
    }

    /** The cycle of the current period (whose period contains today), when the performance year is set. */
    public static function currentCycleId(array $statuses): ?int
    {
        if (self::yearStartMonth() === 0) {
            return null;
        }

        return PerformanceCycle::query()->whereIn('status', $statuses)
            ->where('period_start', '<=', today()->toDateString().' 23:59:59')->where('period_end', '>=', today()->toDateString())
            ->orderByDesc('period_start')->value('id');
    }
}
