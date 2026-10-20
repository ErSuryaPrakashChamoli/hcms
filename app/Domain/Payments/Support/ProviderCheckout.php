<?php

namespace App\Domain\Payments\Support;

/** SaaS.7: the provider's reference for a started payment, and where the payer would go (none for a sandbox). */
final readonly class ProviderCheckout
{
    public function __construct(public string $providerReference, public ?string $redirectUrl = null) {}
}
