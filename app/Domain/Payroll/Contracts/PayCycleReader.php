<?php

namespace App\Domain\Payroll\Contracts;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Read contract from Payroll to the modules that work in payroll months (the monthly attendance upload): which days a
 * payroll month covers under the tenant's pay cycle. Day 1 is the calendar month; day 25 runs November from 25 October
 * to 24 November. A month is named after the month it ends in.
 */
interface PayCycleReader
{
    /** The day of the month every payroll month starts on: 1 (calendar month) to 28. */
    public function startDay(): int;

    /**
     * The days a payroll month covers under the cycle (the first month on a new cycle can differ for a company whose
     * neighbouring month is already fixed; PayCycle::bounds() gives those).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} first and last day
     */
    public function window(int $year, int $month, ?int $startDay = null): array;

    /** @return array{0: int, 1: int} year and month of the payroll month whose window holds the date */
    public function monthOf(CarbonInterface $date, ?int $startDay = null): array;
}
