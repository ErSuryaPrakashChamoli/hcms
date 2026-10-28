<?php

namespace App\Domain\Payroll\Contracts;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Read contract from Payroll to the modules that work in payroll months (the monthly attendance upload): which days a
 * payroll month covers under the tenant's pay cycle. Calendar months until a change; a cycle starting on the 25th runs
 * November from 25 October to 24 November. A month is named after the month it ends in.
 */
interface PayCycleReader
{
    /**
     * The days a payroll month covers under the cycle, the changeover month before a change included (a company's month
     * next to one already fixed by a payroll run can differ; PayCycle::bounds() gives those).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} first and last day
     */
    public function window(int $year, int $month): array;

    /** @return array{0: int, 1: int} year and month of the payroll month whose window holds the date */
    public function monthOf(CarbonInterface $date): array;
}
