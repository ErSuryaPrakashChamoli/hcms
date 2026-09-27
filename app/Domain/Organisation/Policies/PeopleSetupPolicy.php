<?php

namespace App\Domain\Organisation\Policies;

use App\Domain\Identity\Policies\PermissionPolicy;

/** Levels, grades, job families, designations, employment types, employee categories, work modes. */
class PeopleSetupPolicy extends PermissionPolicy
{
    protected string $resource = 'people_setup';
}
