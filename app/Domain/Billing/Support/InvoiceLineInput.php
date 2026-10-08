<?php

namespace App\Domain\Billing\Support;

use App\Support\Money\Money;
use Brick\Math\RoundingMode;

/**
 * SaaS.7: one priced line handed to the invoice drafting service (by the billing run, or tests). A partial first or
 * last month gives the days billed and the days in the month: the line amount is quantity × unit × days ÷ days,
 * rounded once half up (B-3). A generated line names its billing period and carries the frozen quantity evidence.
 * SaaS.7 configuration: the price may be a customer's negotiated version; the rounding is the configured policy
 * frozen on the billing period (B-3: half up).
 */
final readonly class InvoiceLineInput
{
    /** @param  array<string, mixed>|null  $quantityEvidence */
    public function __construct(
        public string $description,
        public int $quantity,
        public Money $unitAmount,
        public string $taxCategory = 'peopleos.subscription',
        public ?int $planPriceVersionId = null,
        public ?int $planVersionId = null,
        public ?string $periodStart = null,
        public ?string $periodEnd = null,
        public ?int $daysBilled = null,
        public ?int $daysInPeriod = null,
        public ?int $billingPeriodId = null,
        public ?array $quantityEvidence = null,
        public ?int $negotiatedPriceVersionId = null,
        public RoundingMode $rounding = RoundingMode::HalfUp,
    ) {}
}
