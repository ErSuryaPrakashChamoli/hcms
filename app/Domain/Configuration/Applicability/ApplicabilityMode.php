<?php

namespace App\Domain\Configuration\Applicability;

/** How a configuration record's companies were chosen (the applicability selector). */
enum ApplicabilityMode: string
{
    /** Every company of the organisation (and, when chosen, companies created later). */
    case All = 'all';

    /** The companies ticked. */
    case Selected = 'selected';

    /** One company: the record's own company, or the one chosen for a tenant-wide record. */
    case Single = 'single';

    public function label(): string
    {
        return match ($this) {
            self::All => 'All entities',
            self::Selected => 'Selected entities',
            self::Single => 'Current entity only',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::All => 'Every company in the organisation uses this one record.',
            self::Selected => 'Only the companies you tick use this record; the others do not see it.',
            self::Single => 'Only one company uses this record.',
        };
    }
}
