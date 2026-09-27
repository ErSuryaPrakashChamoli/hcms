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
