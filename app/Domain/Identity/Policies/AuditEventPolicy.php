<?php

namespace App\Domain\Identity\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Audit records are read-only for everyone, including platform admins (see AuditEvent model).
 */
class AuditEventPolicy extends PermissionPolicy
{
    protected string $resource = 'audit';

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Model $model): bool
    {
        return false;
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Model $model): bool
    {
        return false;
    }

    public function verify(User $user): bool
    {
        return $user->hasPermission('audit.verify');
    }
}
