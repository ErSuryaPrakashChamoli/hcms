<?php

namespace App\Domain\Workflow\Policies;

use App\Domain\Identity\Policies\PermissionPolicy;

class WorkflowPolicy extends PermissionPolicy
{
    protected string $resource = 'workflow';
}
