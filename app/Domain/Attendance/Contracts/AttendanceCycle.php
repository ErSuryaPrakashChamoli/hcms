<?php

namespace App\Domain\Attendance\Contracts;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * HROne parity TX-3: the attendance months a company works in. Attendance does not own the cycle (calendar months, or
 * e.g. the 25th to the 24th, and the dates a month keeps once it has been used downstream); it asks this contract,
 * whose implementation is bound by the module that owns the cycle.
 */
interface AttendanceCycle
{
    /**
     * The first and last day of a company's attendance month (named after the month it ends in).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     *
     * @throws \RuntimeException when the month has no days left between its neighbours
     */
    public function window(int $companyId, int $year, int $month): array;

    /** @return array{0: int, 1: int} year and month of the company's attendance month holding the date */
    public function monthOf(int $companyId, CarbonInterface $date): array;

    /**
     * Whether the month has been finalized downstream (its days are locked for good): it can no longer be reopened, and
     * later corrections follow attendance.allow_corrections_after_lock.
     */
    public function isFinal(int $companyId, int $year, int $month): bool;
}
