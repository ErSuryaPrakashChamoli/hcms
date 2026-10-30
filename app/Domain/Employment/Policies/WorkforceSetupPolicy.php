<?php

namespace App\Domain\Employment\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne setup parity (Workforce): one permission decides a setup master or request type; employee-linked records also
 * follow the organisation scope. Nothing is deleted: masters are made inactive, requests cancelled.
 */
abstract class WorkforceSetupPolicy
{
    abstract protected function permission(): string;

    protected function creatable(): bool
    {
        return true;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission($this->permission());
    }

    public function view(User $user, Model $model): bool
    {
        // Masters have no employee_id column (strict mode refuses reading a missing attribute), so read the raw attributes.
        return $this->viewAny($user) && (($model->getAttributes()['employee_id'] ?? null) === null || app(AccessScopes::class)->allows($user, $model));
    }

    public function create(User $user): bool
    {
        return $this->creatable() && $this->viewAny($user);
    }

    public function update(User $user, Model $model): bool
    {
        return $this->creatable() && $this->view($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
