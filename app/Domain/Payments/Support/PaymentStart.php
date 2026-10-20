<?php

namespace App\Domain\Payments\Support;

use App\Support\Money\Money;

/** SaaS.7: what a provider is asked to collect; the idempotency key makes a retried start return the same session. */
final readonly class PaymentStart
{
    public function __construct(public string $paymentReference, public string $idempotencyKey, public Money $amount, public string $description) {}
}
