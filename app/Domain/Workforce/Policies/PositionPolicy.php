<?php

namespace App\Domain\Workforce\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Workforce\Models\Position;
use App\Domain\Workforce\Services\WorkforceAccess;
use Illuminate\Database\Eloquent\Model;

/**
 * Positions, their versions and change requests: workforce.view / workforce.manage within
 * organisation scope; workforce.team for the positions under the ones a manager holds or held by the
 * employees they manage. Writes go through the Positions service, which re-checks. Never deleted.
 */
class PositionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('workforce.view') || $user->hasPermission('workforce.manage') || $user->hasPermission('workforce.team');
    }

    public function view(User $user, Model $model): bool
    {
        $position = $this->position($model);

        return $position !== null && app(WorkforceAccess::class)->mayView($user, $position);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('workforce.manage');
    }

    public function update(User $user, Model $model): bool
    {
        $position = $this->position($model);

        return $position !== null && app(WorkforceAccess::class)->mayManage($user, $position);
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    private function position(Model $model): ?Position
    {
        return $model instanceof Position ? $model : Position::query()->withoutGlobalScope(AccessScope::class)->find($model->getAttribute('position_id'));
    }
}
