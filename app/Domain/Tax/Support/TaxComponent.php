<?php

namespace App\Domain\Tax\Support;

use InvalidArgumentException;

/** SaaS.7: one tax applied by a rule outcome: its type (CGST, VAT, …) and its rate per cent as an exact decimal. */
final readonly class TaxComponent
{
    public function __construct(public string $type, public string $rate)
    {
        if (preg_match('/^(100(\.0{1,4})?|\d{1,2}(\.\d{1,4})?)$/', $rate) !== 1) {
            throw new InvalidArgumentException("{$rate} is not a rate per cent between 0 and 100 with at most 4 decimals.");
        }
    }
}
