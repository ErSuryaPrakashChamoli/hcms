<?php

namespace App\Domain\Organisation\Policies;

use App\Domain\Identity\Policies\PermissionPolicy;

class CompanyPolicy extends PermissionPolicy
{
    protected string $resource = 'company';
}
