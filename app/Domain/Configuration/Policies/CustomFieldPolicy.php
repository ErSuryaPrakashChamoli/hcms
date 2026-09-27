<?php

namespace App\Domain\Configuration\Policies;

use App\Domain\Identity\Policies\PermissionPolicy;

class CustomFieldPolicy extends PermissionPolicy
{
    protected string $resource = 'custom_field';
}
