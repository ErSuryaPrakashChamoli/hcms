<?php

namespace App\Domain\Compliance\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only for everyone inside a tenant; rule versions are published by the platform packs and
 * moved through verification by platform administrators only (RuleVerifications), never edited.
 */
class ComplianceRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isPlatformAdmin() || $user->hasPermission('compliance.view') || $user->hasPermission('payroll.view');
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
