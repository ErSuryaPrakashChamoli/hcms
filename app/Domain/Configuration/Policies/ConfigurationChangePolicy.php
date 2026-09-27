<?php

namespace App\Domain\Configuration\Policies;

use App\Domain\Identity\Policies\PermissionPolicy;

class ConfigurationChangePolicy extends PermissionPolicy
{
    protected string $resource = 'configuration';
}
