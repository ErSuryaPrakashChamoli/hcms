<?php

namespace App\Domain\Compensation\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** The part of a payroll window one approved compensation covers, with its structure's components. */
final class CompensationSegment
{
    /** @param  Collection<int, CompensationComponentLine>  $components */
    public function __construct(
        public readonly CompensationSnapshot $compensation,
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly Collection $components,
    ) {}
}
