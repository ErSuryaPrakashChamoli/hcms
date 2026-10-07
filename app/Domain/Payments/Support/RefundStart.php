<?php

namespace App\Domain\Payments\Support;

use App\Support\Money\Money;

/**
 * SaaS.7 completion (B-12): what a provider is asked to refund: an amount of a captured payment, keyed by the refund's
 * own reference so a retried request finds the refund already made instead of making a second one.
 */
final readonly class RefundStart
{
    public function __construct(public string $refundReference, public string $paymentReference, public ?string $transactionReference, public Money $amount) {}
}
