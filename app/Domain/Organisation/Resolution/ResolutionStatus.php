<?php

namespace App\Domain\Organisation\Resolution;

/** The outcome of resolving one typed reference (a name, a code, or both) to a master record. */
enum ResolutionStatus: string
{
    /** Exactly one record: by code, by exact name, or by a confirmed mapping. */
    case Matched = 'matched';

    /** A record an earlier worksheet of the same workbook will create (id -1 until it exists). */
    case Planned = 'planned';

    /** Nothing was typed. Whether that is allowed is the caller's rule. */
    case Blank = 'blank';

    /** Nothing matched exactly, but similar records exist. They are offered, never chosen automatically. */
    case Suggested = 'suggested';

    /** Nothing matched, and nothing is similar. */
    case Unmatched = 'unmatched';

    /** Several records have this name for this company. */
    case Ambiguous = 'ambiguous';

    /** The name and the code were both given and do not describe the same record. */
    case Conflict = 'conflict';

    /** The record exists but does not apply to the row's company. */
    case NotApplicable = 'not_applicable';

    public function ok(): bool
    {
        return in_array($this, [self::Matched, self::Planned, self::Blank], true);
    }

    /** Whether a person can settle it in the review (map to a record, or create the missing one). */
    public function reviewable(): bool
    {
        return in_array($this, [self::Suggested, self::Unmatched, self::Ambiguous], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Matched => 'Matched',
            self::Planned => 'Created by this workbook',
            self::Blank => 'Blank',
            self::Suggested => 'Not found: similar names',
            self::Unmatched => 'Not available',
            self::Ambiguous => 'Ambiguous',
            self::Conflict => 'Name and code disagree',
            self::NotApplicable => 'Not available to this company',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Matched, self::Planned => 'success',
            self::Blank => 'gray',
            self::Suggested, self::Ambiguous => 'warning',
            self::Unmatched, self::Conflict, self::NotApplicable => 'danger',
        };
    }
}
