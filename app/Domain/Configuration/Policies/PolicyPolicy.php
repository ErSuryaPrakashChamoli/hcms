<?php

namespace App\Domain\Configuration\Policies;

use App\Domain\Identity\Policies\PermissionPolicy;

class PolicyPolicy extends PermissionPolicy
{
    protected string $resource = 'policy';
}
