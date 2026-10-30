<?php

namespace App\Domain\Performance\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Performance\Models\Initiative;
use App\Domain\Performance\Services\Initiatives;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne setup parity HP-8j: initiatives are seen and changed as Initiatives decides — the employee, their performance
 * managers, HR (performance.initiatives_manage; performance.view to read) within scope, and a colleague asked for
 * feedback (to read). Initiatives are cancelled, never deleted.
 */
class InitiativePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('performance.initiatives') || $user->hasPermission('performance.initiatives_manage') || $user->hasPermission('performance.view');
    }

    public function view(User $user, Model $model): bool
    {
        return $model instanceof Initiative && app(Initiatives::class)->mayView($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('performance.initiatives') || $user->hasPermission('performance.initiatives_manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $model instanceof Initiative && app(Initiatives::class)->mayEdit($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
