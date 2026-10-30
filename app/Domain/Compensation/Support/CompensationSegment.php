<?php

namespace App\Domain\Compensation\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** The part of a payroll window one approved compensation covers, with its structure version's components and parameters. */
final class CompensationSegment
{
    /**
     * @param  Collection<int, CompensationComponentLine>  $components
     * @param  array<string, float>  $parameters  the version's named numbers its formulas use (basic_pct => 50)
     * @param  string  $ctcMode  the version's CTC mode: `ctc` (components balance to the CTC) or `components` (the CTC is worked
     *                           out from the component amounts, HROne setup parity BP-2); previews need it (HROne parity PO-3)
     */
    public function __construct(
        public readonly CompensationSnapshot $compensation,
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly Collection $components,
        public readonly array $parameters = [],
        public readonly string $ctcMode = 'ctc',
    ) {}
}
