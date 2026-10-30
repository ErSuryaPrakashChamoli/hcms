<?php

namespace App\Domain\Documents\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\PermissionPolicy;
use Illuminate\Database\Eloquent\Model;

class DocumentTypePolicy extends PermissionPolicy
{
    protected string $resource = 'document';

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('document.types') || $user->hasPermission('document.view');
    }

    /** HROne setup parity CA-4: a type's details (and its sample file) are open to employees who keep their own documents. */
    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission('document.types') || $user->hasPermission('document.view') || $user->hasPermission('document.own');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('document.types');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('document.types');
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->hasPermission('document.types');
    }
}
