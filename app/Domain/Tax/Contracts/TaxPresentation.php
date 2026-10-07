<?php

namespace App\Domain\Tax\Contracts;

use App\Domain\Tax\Enums\TaxRegime;

/** SaaS.7: what a regime adds to an invoice's presentation (labelled rows), from the invoice's frozen tax snapshot. */
interface TaxPresentation
{
    public function regime(): TaxRegime;

    /**
     * @param  array<string, mixed>  $taxSnapshot
     * @return list<array{label: string, value: string}>
     */
    public function rows(array $taxSnapshot): array;
}
