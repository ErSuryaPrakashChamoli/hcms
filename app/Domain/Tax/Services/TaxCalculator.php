<?php

namespace App\Domain\Tax\Services;

use App\Domain\Tax\Enums\TaxRounding;
use App\Domain\Tax\Support\TaxCalculation;
use App\Domain\Tax\Support\TaxComponent;
use App\Domain\Tax\Support\TaxLine;
use App\Support\Money\Currency;
use App\Support\Money\Money;

/**
 * SaaS.7: tax arithmetic as a pure function: no database, no clock, no HTTP. Each component is applied to each
 * line's taxable amount and rounded once, per line and component, to the currency's minor unit with the rule's
 * rounding mode; the invoice tax is the sum of those rounded amounts. A treatment without components yields no
 * tax lines and zero tax (tax is never assumed to be rate × amount for every customer).
 */
final class TaxCalculator
{
    /**
     * @param  array<int, Money>  $taxable  line number => taxable amount
     * @param  list<TaxComponent>  $components
     */
    public static function calculate(Currency $currency, array $taxable, array $components, TaxRounding $rounding): TaxCalculation
    {
        $lines = [];
        $total = Money::zero($currency);
        foreach ($taxable as $lineNo => $amount) {
            foreach ($components as $component) {
                $tax = $amount->percentage($component->rate, $rounding->mode());
                $lines[] = new TaxLine($lineNo, $component->type, $component->rate, $amount, $tax);
                $total = $total->plus($tax);
            }
        }

        return new TaxCalculation($lines, $total);
    }
}
