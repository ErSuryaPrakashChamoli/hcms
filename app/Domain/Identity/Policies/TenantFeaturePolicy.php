<?php

namespace App\Domain\Identity\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Services\FeatureFlags;
use Illuminate\Database\Eloquent\Model;

class TenantFeaturePolicy extends PermissionPolicy
{
    protected string $resource = 'features';

    public function create(User $user): bool
    {
        return false;
    }

    /** A platform-controlled flag is switched from the platform console only (FeatureFlags::setForTenant). */
    public function update(User $user, Model $model): bool
    {
        return ! FeatureFlags::platformControlled((string) $model->getAttribute('feature')) && parent::update($user, $model);
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
