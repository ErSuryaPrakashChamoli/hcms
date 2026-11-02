<?php

namespace App\Domain\People\Policies;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\ProfileSectionVisibility;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\PermissionPolicy;
use App\Domain\Identity\Scopes\AccessScope;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity MS-5: health insurance policies and insured members are sensitive personal data, read and changed like the
 * bank and identity tabs — employee.sensitive.view to see (within organisation scope), employee.sensitive.update to
 * change — and by the employee only for their own record (when the CA-6 matrix shows them the health insurance section).
 * Every write still goes through InsuranceRecords, which checks again.
 */
class InsurancePolicyPolicy extends PermissionPolicy
{
    protected string $resource = 'employee';

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('employee.sensitive.view');
    }

    public function view(User $user, Model $model): bool
    {
        return ($user->hasPermission('employee.sensitive.view') && $this->withinScope($user, $model)) || self::isOwn($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('employee.sensitive.update');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('employee.sensitive.update') && $this->withinScope($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->update($user, $model);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Model $model): bool
    {
        return false;
    }

    /** The employee's own policy (or member), while the profile-access matrix shows them their health insurance. */
    public static function isOwn(User $user, Model $model): bool
    {
        if ((int) $model->getAttribute('tenant_id') !== (int) $user->getAttribute('tenant_id') || ! $user->hasPermission('employee.self')) {
            return false;
        }
        $owner = Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($model->getAttribute('employee_id'))->value('user_id');

        return $owner !== null && (int) $owner === (int) $user->getKey()
            && app(ProfileSectionVisibility::class)->allowsAudience('employee', 'health_insurance');
    }
}
