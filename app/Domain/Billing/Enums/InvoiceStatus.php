<?php

namespace App\Domain\Billing\Enums;

/** SaaS.7: draft → issued → paid, or draft → discarded. Overdue is derived from the due date, never stored. */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Paid = 'paid';
    case Discarded = 'discarded';

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Issued => 'warning',
            self::Paid => 'success',
            self::Discarded => 'danger',
        };
    }
}
