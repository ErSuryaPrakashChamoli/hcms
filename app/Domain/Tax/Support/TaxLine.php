<?php

namespace App\Domain\Tax\Support;

use App\Support\Money\Money;

/** SaaS.7: the tax of one component on one invoice line. */
final readonly class TaxLine
{
    public function __construct(public int $lineNo, public string $type, public string $rate, public Money $taxable, public Money $tax) {}
}
