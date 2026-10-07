<?php

namespace App\Domain\Tax\Support;

use App\Domain\Tax\Enums\CustomerType;
use App\Domain\Tax\Enums\TaxIdType;
use App\Domain\Tax\Enums\TaxRegistration;

/** SaaS.7: one side of a supply as tax sees it (supplier or customer): where it is and how it is registered. */
final readonly class TaxParty
{
    public function __construct(
        public TaxJurisdiction $jurisdiction,
        public TaxRegistration $registration,
        public ?TaxIdType $taxIdType = null,
        public ?string $taxIdValue = null,
        public ?CustomerType $customerType = null,
        public ?string $specialStatus = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->jurisdiction->toArray() + ['registration' => $this->registration->value, 'tax_id_type' => $this->taxIdType?->value,
            'tax_id' => $this->taxIdValue, 'customer_type' => $this->customerType?->value, 'special_status' => $this->specialStatus];
    }
}
