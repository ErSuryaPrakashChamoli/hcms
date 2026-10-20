<?php

namespace App\Domain\Tax\Support;

use App\Support\Money\Currency;

/** SaaS.7: everything tax determination may use. Nothing else (no IP, locale, server or employee location). */
final readonly class TaxContext
{
    public function __construct(
        public TaxParty $supplier,
        public TaxParty $customer,
        public string $taxCategory,
        public string $taxPoint,
        public Currency $currency,
    ) {}
}
