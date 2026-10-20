<?php

namespace App\Domain\Employment\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\PermissionPolicy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

class EmployeePolicy extends PermissionPolicy
{
    protected string $resource = 'employee';

    /**
     * employee.view opens every employee in the viewer's scope. UX.19 (G12): the Employee 360 is also its subject's own
     * record, so employee.self opens the one record linked to the signed-in user, in the current tenant. It grants
     * nothing else: viewAny, update, delete, sensitive data and every section's own rule are unchanged.
     */
    public function view(User $user, Model $model): bool
    {
        return parent::view($user, $model) || self::isOwnRecord($user, $model);
    }

    public static function isOwnRecord(User $user, Model $model): bool
    {
        $tenant = $model->getAttribute('tenant_id');

        return $user->hasPermission('employee.self')
            && $model->getAttribute('user_id') !== null && (int) $model->getAttribute('user_id') === (int) $user->getKey()
            && $tenant !== null && (int) $tenant === (int) $user->tenant_id && (int) $tenant === (int) app(TenantContext::class)->id();
    }

    public function viewSensitive(User $user, Model $model): bool
    {
        return $user->hasPermission('employee.sensitive.view');
    }

    public function updateSensitive(User $user, Model $model): bool
    {
        return $user->hasPermission('employee.sensitive.update');
    }

    public function assignPosition(User $user, Model $model): bool
    {
        return $user->hasPermission('employee.position');
    }

    public function transition(User $user, Model $model): bool
    {
        return $user->hasPermission('employee.lifecycle');
    }
}
