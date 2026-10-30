<?php

namespace App\Domain\Employment\Policies;

class TransferRequestPolicy extends WorkforceSetupPolicy
{
    protected function permission(): string
    {
        return 'employee.position';
    }

    protected function creatable(): bool
    {
        return false; // requested and decided through TransferRequests
    }
}
