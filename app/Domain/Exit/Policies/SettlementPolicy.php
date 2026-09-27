<?php

namespace App\Domain\Exit\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

class SettlementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('exit.settle') || $user->hasPermission('exit.view');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user) || ExitCasePolicy::isOwn($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('exit.settle');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('exit.settle');
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
