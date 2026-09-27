<?php

namespace App\Domain\Compliance\Jobs;

use App\Domain\Compliance\Services\Returns\EsiReturns;

class GenerateEsiReturn extends GeneratePayrollLineReturn
{
    protected function generator(): string
    {
        return EsiReturns::class;
    }
}
