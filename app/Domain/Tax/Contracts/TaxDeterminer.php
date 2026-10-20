<?php

namespace App\Domain\Tax\Contracts;

use App\Domain\Tax\Enums\TaxRegime;
use App\Domain\Tax\Exceptions\TaxUnavailableException;
use App\Domain\Tax\Support\TaxContext;
use App\Domain\Tax\Support\TaxDetermination;

/** SaaS.7: a regime's determination logic (code, per jurisdiction). Throws rather than guess. */
interface TaxDeterminer
{
    public function regime(): TaxRegime;

    /** @return array<string, string> the outcome keys this determiner can produce => what each means (a rule prices them) */
    public function outcomes(): array;

    /** @throws TaxUnavailableException */
    public function determine(TaxContext $context): TaxDetermination;
}
