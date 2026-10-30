<?php

namespace App\Domain\Assets\Policies;

use App\Domain\Assets\Models\AssetRequest;
use App\Domain\Assets\Services\AssetRequests;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne setup parity AS-B: asset requests. The asset team (`asset.view` / `asset.assign` / `asset.manage`) sees them in
 * its access scope; employees with `asset.request` see their own, what they raised and their team's; holders of an asset
 * type's owner role see the requests they fulfil. Deciding and fulfilling are checked by AssetRequests.
 */
class AssetRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('asset.request') || self::team($user);
    }

    public function view(User $user, Model $model): bool
    {
        if (! $model instanceof AssetRequest) {
            return false;
        }
        if (self::team($user) && app(AccessScopes::class)->allows($user, $model)) {
            return true;
        }
        $requests = app(AssetRequests::class);

        return (int) Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($model->employee_id)->value('user_id') === (int) $user->id
            || (int) $model->requested_by === (int) $user->id
            || $requests->canApprove($user, $model)
            || $requests->canFulfil($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('asset.request') || $user->hasPermission('asset.assign') || $user->hasPermission('asset.manage');
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

    /** The asset team: sees every request in scope. */
    public static function team(User $user): bool
    {
        return $user->hasPermission('asset.view') || $user->hasPermission('asset.assign') || $user->hasPermission('asset.manage');
    }
}
