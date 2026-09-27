<?php

namespace App\Domain\Compliance\Jobs;

use App\Domain\Compliance\Services\Returns\LwfReturns;

class GenerateLwfReturn extends GeneratePayrollLineReturn
{
    protected function generator(): string
    {
        return LwfReturns::class;
    }
}
