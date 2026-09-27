<?php

namespace App\Domain\Compliance\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/** Indian financial year helpers (April–March by default). */
final class FinancialYear
{
    public function __construct(private readonly int $startMonth = 4) {}

    public static function make(): self
    {
        return new self((int) config('peopleos.compliance.financial_year_start_month', 4));
    }

    /** "2025-26" for any date in that FY. */
    public function label(CarbonInterface|string $date): string
    {
        $start = $this->startYear($date);

        return $start.'-'.substr((string) ($start + 1), -2);
    }

    public function startYear(CarbonInterface|string $date): int
    {
        $d = Carbon::parse($date);

        return $d->month >= $this->startMonth ? $d->year : $d->year - 1;
    }

    public function start(CarbonInterface|string $date): Carbon
    {
        return Carbon::create($this->startYear($date), $this->startMonth, 1)->startOfDay();
    }

    public function end(CarbonInterface|string $date): Carbon
    {
        return $this->start($date)->addYear()->subDay()->endOfDay();
    }

    /** 1-based index of the month within the FY (April = 1). */
    public function monthIndex(CarbonInterface|string $date): int
    {
        $d = Carbon::parse($date);

        return (($d->month - $this->startMonth + 12) % 12) + 1;
    }

    public function monthsRemainingIncluding(CarbonInterface|string $date): int
    {
        return 13 - $this->monthIndex($date);
    }
}
