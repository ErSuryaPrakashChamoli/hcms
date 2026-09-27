<?php

namespace App\Domain\Employment\Policies;

use App\Domain\Identity\Policies\PermissionPolicy;

/** Person satellites, positions, reporting lines, timeline: governed by employee.* keys. */
class EmployeeDataPolicy extends PermissionPolicy
{
    protected string $resource = 'employee';
}
