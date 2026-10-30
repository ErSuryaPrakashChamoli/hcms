<?php

namespace App\Domain\Employment\Policies;

class WorkforceReasonPolicy extends WorkforceSetupPolicy
{
    protected function permission(): string
    {
        return 'employee.lifecycle';
    }
}
