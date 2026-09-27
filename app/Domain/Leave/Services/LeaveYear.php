<?php

namespace App\Domain\Leave\Services;

use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Carbon;

/** The leave year (period) a date belongs to; starts on a tenant-configured month (1 = Jan, 4 = Apr). */
final class LeaveYear
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function startMonth(): int
    {
        return max(1, min(12, (int) $this->settings->get('leave.year_start_month', 1)));
    }

    public function periodFor(Carbon|string $date): int
    {
        $date = Carbon::parse($date);

        return $date->month >= $this->startMonth() ? $date->year : $date->year - 1;
    }

    public function start(int $period): Carbon
    {
        return Carbon::create($period, $this->startMonth(), 1)->startOfDay();
    }

    public function end(int $period): Carbon
    {
        return $this->start($period)->addYear()->subDay()->endOfDay();
    }
}
