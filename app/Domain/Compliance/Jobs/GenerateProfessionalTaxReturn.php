<?php

namespace App\Domain\Compliance\Jobs;

use App\Domain\Compliance\Services\Returns\ProfessionalTaxReturns;

class GenerateProfessionalTaxReturn extends GeneratePayrollLineReturn
{
    protected function generator(): string
    {
        return ProfessionalTaxReturns::class;
    }
}
