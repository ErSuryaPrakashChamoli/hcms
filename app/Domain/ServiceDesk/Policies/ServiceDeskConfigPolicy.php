<?php

namespace App\Domain\ServiceDesk\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

class ServiceDeskConfigPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('servicedesk.manage') || $user->hasPermission('servicedesk.view');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('servicedesk.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('servicedesk.manage');
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->hasPermission('servicedesk.manage');
    }
}
