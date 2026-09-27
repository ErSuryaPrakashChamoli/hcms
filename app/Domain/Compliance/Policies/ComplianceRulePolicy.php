<?php

namespace App\Domain\Compliance\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Read-only for everyone inside a tenant; only the platform sync writes rules (§101). */
class ComplianceRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('compliance.view') || $user->hasPermission('payroll.view');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

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
}
