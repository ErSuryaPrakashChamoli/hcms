<?php

namespace App\Domain\Tax\Support;

use App\Domain\Tax\Enums\CustomerType;
use App\Domain\Tax\Enums\TaxIdType;
use App\Domain\Tax\Enums\TaxRegistration;

/**
 * SaaS.7: one side of a supply as tax sees it (supplier or customer): where it is and how it is registered. A supplier
 * may also hold registrations and undertakings elsewhere (an Indian LUT, a UK VAT number, a US state permit), each
 * with its validity, which rule conditions can require.
 */
final readonly class TaxParty
{
    public function __construct(
        public TaxJurisdiction $jurisdiction,
        public TaxRegistration $registration,
        public ?TaxIdType $taxIdType = null,
        public ?string $taxIdValue = null,
        public ?CustomerType $customerType = null,
        public ?string $specialStatus = null,
        public array $registrations = [],
    ) {}

    /** The registration or undertaking of $type valid on $day, if the party holds one. @return array<string, mixed>|null */
    public function registration(string $type, string $day): ?array
    {
        foreach ($this->registrations as $r) {
            if (($r['type'] ?? null) === $type && ($r['valid_from'] ?? '0000-00-00') <= $day && (($r['valid_to'] ?? null) === null || $r['valid_to'] >= $day)) {
                return $r;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->jurisdiction->toArray() + ['registration' => $this->registration->value, 'tax_id_type' => $this->taxIdType?->value,
            'tax_id' => $this->taxIdValue, 'customer_type' => $this->customerType?->value, 'special_status' => $this->specialStatus,
            'registrations' => array_values(array_map(fn (array $r) => array_intersect_key($r, array_flip(['type', 'reference', 'valid_from', 'valid_to'])), $this->registrations))];
    }
}
