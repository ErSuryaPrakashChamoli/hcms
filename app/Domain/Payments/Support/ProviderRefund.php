<?php

namespace App\Domain\Payments\Support;

/** SaaS.7 completion (B-12): the provider's view of a refund: its reference and processing | succeeded | failed. */
final readonly class ProviderRefund
{
    public function __construct(public string $providerRefundReference, public string $status, public ?string $failureCode = null) {}
}
