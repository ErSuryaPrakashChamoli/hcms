<?php

namespace App\Domain\Billing\Support;

use App\Support\Money\Money;

/** SaaS.7: one priced line handed to the invoice drafting service (by the future billing calculation, or tests). */
final readonly class InvoiceLineInput
{
    public function __construct(
        public string $description,
        public int $quantity,
        public Money $unitAmount,
        public string $taxCategory = 'peopleos.subscription',
        public ?int $planPriceVersionId = null,
        public ?int $planVersionId = null,
        public ?string $periodStart = null,
        public ?string $periodEnd = null,
    ) {}
}
