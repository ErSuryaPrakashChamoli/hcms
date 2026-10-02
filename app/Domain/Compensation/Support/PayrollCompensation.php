<?php

namespace App\Domain\Compensation\Support;

use Illuminate\Support\Collection;

/** What Payroll receives for one employee and window: approved segments, oldest first, and their fingerprint. */
final class PayrollCompensation
{
    /** @param  Collection<int, CompensationSegment>  $segments */
    public function __construct(
        public readonly Collection $segments,
        public readonly string $fingerprint,
        public readonly string $contractVersion,
    ) {}

    public function last(): ?CompensationSegment
    {
        return $this->segments->last();
    }

    public function isEmpty(): bool
    {
        return $this->segments->isEmpty();
    }
}
