<?php

namespace App\Domain\Organisation\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\PermissionPolicy;

/** Locations, business units, divisions, departments, teams, cost/profit centres, org nodes. */
class OrganisationStructurePolicy extends PermissionPolicy
{
    protected string $resource = 'organisation';

    public function design(User $user): bool
    {
        return $user->hasPermission('organisation.design');
    }
}
