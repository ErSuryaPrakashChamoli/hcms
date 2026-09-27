<?php

namespace App\Domain\Identity\Policies;

/**
 * Tenants are platform objects. Gate::before grants platform admins everything; nobody else
 * gets through, regardless of role configuration (protected control, see blueprint §101).
 */
class TenantPolicy extends PermissionPolicy
{
    protected string $resource = 'tenant';

    public function viewAny($user): bool
    {
        return false;
    }

    public function view($user, $model): bool
    {
        return false;
    }

    public function create($user): bool
    {
        return false;
    }

    public function update($user, $model): bool
    {
        return false;
    }

    public function delete($user, $model): bool
    {
        return false;
    }

    public function deleteAny($user): bool
    {
        return false;
    }
}
