<?php

namespace App\Domain\Tax\Support;

use App\Support\Money\Money;

/** SaaS.7: the result of calculating tax on a set of lines: one TaxLine per line and component, and the total. */
final readonly class TaxCalculation
{
    /** @param  list<TaxLine>  $lines */
    public function __construct(public array $lines, public Money $total) {}

    /** @return array<string, Money> tax type => total, in first-seen order */
    public function totalsByType(): array
    {
        $totals = [];
        foreach ($this->lines as $line) {
            $totals[$line->type] = isset($totals[$line->type]) ? $totals[$line->type]->plus($line->tax) : $line->tax;
        }

        return $totals;
    }
}
