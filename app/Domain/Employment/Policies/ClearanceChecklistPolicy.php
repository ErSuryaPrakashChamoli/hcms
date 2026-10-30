<?php

namespace App\Domain\Employment\Policies;

class ClearanceChecklistPolicy extends WorkforceSetupPolicy
{
    protected function permission(): string
    {
        return 'exit.manage';
    }
}
