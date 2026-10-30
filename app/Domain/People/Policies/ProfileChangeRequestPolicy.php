<?php

namespace App\Domain\People\Policies;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\PermissionPolicy;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\People\Models\ProfileChangeRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity MS-3: a profile change request is seen by the person it is about (and whoever raised it) and by the people
 * who maintain employee records in their scope. Nobody creates, edits or deletes one through a policy: ProfileChanges
 * submits, decides and withdraws them (its own checks), and requests are never deleted.
 */
class ProfileChangeRequestPolicy extends PermissionPolicy
{
    protected string $resource = 'employee';

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('employee.self') || $user->hasPermission('employee.update');
    }

    public function view(User $user, Model $model): bool
    {
        if (! $model instanceof ProfileChangeRequest) {
            return false;
        }

        return self::isOwn($user, $model) || ($user->hasPermission('employee.update') && app(AccessScopes::class)->allows($user, $model));
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

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public static function isOwn(User $user, ProfileChangeRequest $request): bool
    {
        if ((int) $request->getAttribute('tenant_id') !== (int) $user->getAttribute('tenant_id')) {
            return false;
        }
        if ((int) $request->getAttribute('requested_by') === (int) $user->getKey()) {
            return true;
        }
        $owner = Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($request->getAttribute('employee_id'))->value('user_id');

        return $owner !== null && (int) $owner === (int) $user->getKey();
    }
}
