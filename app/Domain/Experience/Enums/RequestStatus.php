<?php

namespace App\Domain\Experience\Enums;

/**
 * HROne parity RQ-1: where one of the person's own requests stands, across every request type (HROne's request tracker
 * tabs Pending / Approved / Rejected / Undo / Drafts). Each request type maps its own statuses onto these; the row still
 * shows the module's own wording.
 */
enum RequestStatus: string
{
    /** Waiting for someone else: a decision, or a decision on a cancellation. */
    case Pending = 'pending';
    /** Approved, granted or done (paid, fulfilled, resolved, verified). */
    case Approved = 'approved';
    case Rejected = 'rejected';
    /** Withdrawn or cancelled before it took effect, or cancelled later; HROne calls it "Undo". */
    case Withdrawn = 'withdrawn';
    /** Saved or sent back to the person, not (yet) submitted. */
    case Draft = 'draft';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Withdrawn => 'Withdrawn',
            self::Draft => 'Drafts',
        };
    }

    /** The tone of the status badge (PeopleOS `pos-*` tones). */
    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Withdrawn, self::Draft => 'neutral',
        };
    }

    /** Whether nothing more is waiting on anyone (the person may hide it from their list). */
    public function isClosed(): bool
    {
        return in_array($this, [self::Approved, self::Rejected, self::Withdrawn], true);
    }
}
