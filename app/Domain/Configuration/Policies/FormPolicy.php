<?php

namespace App\Domain\Configuration\Policies;

use App\Domain\Identity\Policies\PermissionPolicy;

class FormPolicy extends PermissionPolicy
{
    protected string $resource = 'form';
}
