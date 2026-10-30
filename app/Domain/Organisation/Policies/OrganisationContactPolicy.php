<?php

namespace App\Domain\Organisation\Policies;

use App\Domain\Identity\Policies\PermissionPolicy;

/** HROne setup parity CA-2: organisation contacts follow the company permissions. */
class OrganisationContactPolicy extends PermissionPolicy
{
    protected string $resource = 'company';
}
