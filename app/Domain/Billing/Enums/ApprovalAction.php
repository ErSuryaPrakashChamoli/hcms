<?php

namespace App\Domain\Billing\Enums;

/**
 * SaaS.7 completion (B-13): the financial operations that need a second operator (maker-checker, no thresholds).
 * Drafting, issuing, recording transfers, billing profiles and terms stay single operator + reason; tax rules keep
 * their own second-operator verification.
 */
enum ApprovalAction: string
{
    case PricePublication = 'price_publication';
    case CreditNote = 'credit_note';                       // full (the invoice's cancellation) or partial
    case Refund = 'refund';                                // only against a credit note
    case InvoiceWriteOff = 'invoice_write_off';            // an unpaid invoice
    case ExceptionResolution = 'exception_resolution';     // accept or write off a payment reconciliation exception

    public function label(): string
    {
        return match ($this) {
            self::PricePublication => 'Price publication',
            self::CreditNote => 'Credit note',
            self::Refund => 'Refund',
            self::InvoiceWriteOff => 'Invoice write-off',
            self::ExceptionResolution => 'Payment exception resolution',
        };
    }
}
