<?php

namespace App\Domain\Engagement\Policies;

use App\Domain\Engagement\Models\FundraiserCampaign;
use App\Domain\Engagement\Models\FundraiserPledge;
use App\Domain\Engagement\Services\Fundraisers;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Identity\Services\CurrentEmployee;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity GO-18: fundraisers are run by HR (engagement.manage) and seen by employees (engagement.participate) only
 * when one is open to them or they pledged to it. A pledge is seen by its donor and by HR within their access scope.
 * Nothing is deleted; every write goes through Fundraisers, which checks again.
 */
class FundraiserPolicy
{
    public function viewAny(User $user): bool
    {
        $fundraisers = app(Fundraisers::class);

        return $fundraisers->manages($user) || $fundraisers->hasAnyFor($user);
    }

    public function view(User $user, Model $model): bool
    {
        $fundraisers = app(Fundraisers::class);
        if ($model instanceof FundraiserPledge) {
            return ($fundraisers->manages($user) && app(AccessScopes::class)->allowsEmployeeId($user, (int) $model->employee_id))
                || (int) (app(CurrentEmployee::class)->of($user)?->id ?? 0) === (int) $model->employee_id;
        }

        return $model instanceof FundraiserCampaign && $fundraisers->canSee($user, $model);
    }

    public function create(User $user): bool
    {
        return app(Fundraisers::class)->manages($user);
    }

    public function update(User $user, Model $model): bool
    {
        return $model instanceof FundraiserCampaign && $model->status === 'draft' && app(Fundraisers::class)->manages($user);
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
