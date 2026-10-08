<?php

namespace App\Domain\Employment\Services;

use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;

/**
 * Phase 12: the authorization every People / Employment profile change action applies itself —
 * tenant (the employee must be reachable in the current tenant), permission, organisation scope —
 * whatever the caller (Employee 360, an approved service request, the API). Screens may hide fields,
 * but the action is what decides.
 */
final class ProfileChangeGuard
{
    public function __construct(private readonly AccessScopes $scopes) {}

    public function authorize(User $actor, Employee $employee, string $permission): void
    {
        if (! $actor->isActive() && ! $actor->is_platform_admin) {
            throw new ProfileChangeRefused('Only an active user can change employee data.');
        }
        if (! $actor->hasPermission($permission)) {
            throw new ProfileChangeRefused("This needs {$permission}.");
        }
        if (! $this->scopes->allows($actor, $employee)) {
            throw new ProfileChangeRefused('That employee is outside your organisation scope.');
        }
    }
}
