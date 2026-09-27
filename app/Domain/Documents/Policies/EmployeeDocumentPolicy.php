<?php

namespace App\Domain\Documents\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use Illuminate\Database\Eloquent\Model;

class EmployeeDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('document.view');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission('document.view') && app(AccessScopes::class)->allows($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('document.upload');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('document.upload');
    }

    public function verify(User $user, Model $model): bool
    {
        return $user->hasPermission('document.verify');
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->hasPermission('document.delete');
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasPermission('document.delete');
    }
}
