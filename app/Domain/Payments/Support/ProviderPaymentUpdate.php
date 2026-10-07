<?php

namespace App\Domain\Payments\Support;

use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Support\Money\Money;

/**
 * SaaS.7: the normalised outcome of a payment, from a verified provider event, a server-side provider fetch or an
 * operator-recorded transfer. The only payment vocabulary reconciliation sees: provider fields stay in adapters.
 * $settlement is what the provider (or bank) credits Markedge, usually INR (B-14), with $settlementSource naming who
 * reported it; it never changes the payment's or the invoice's amount. $transactionReference is the provider's id
 * of the captured transaction (what a refund is made against), when it differs from the payment reference.
 */
final readonly class ProviderPaymentUpdate
{
    public function __construct(
        public string $providerReference,
        public PaymentStatus $status,
        public ?Money $amount = null,
        public ?PaymentMethod $method = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
        public ?Money $settlement = null,
        public ?string $transactionReference = null,
        public ?string $settlementSource = null,
    ) {}
}
