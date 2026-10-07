<?php

namespace App\Domain\Billing\Enums;

/**
 * SaaS.7: draft → issued → paid, or draft → discarded. After issue (B-11, B-12, B-13): partially paid only while a
 * declared customer TDS awaits its certificate; credited when credit notes cover the whole total (a full credit
 * note is the cancellation); written off by an approved write-off. Overdue is derived from the due date, never stored.
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Credited = 'credited';
    case WrittenOff = 'written_off';
    case Discarded = 'discarded';

    /** Issued and not yet settled, credited or written off: money is still expected. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Issued, self::PartiallyPaid], true);
    }

    /** Has a number and is a tax document (everything after issue). */
    public function isIssuedDocument(): bool
    {
        return ! in_array($this, [self::Draft, self::Discarded], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::PartiallyPaid => 'partially paid (TDS certificate pending)',
            self::WrittenOff => 'written off',
            default => $this->value,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Issued, self::PartiallyPaid => 'warning',
            self::Paid => 'success',
            self::Credited => 'info',
            self::WrittenOff, self::Discarded => 'danger',
        };
    }
}
