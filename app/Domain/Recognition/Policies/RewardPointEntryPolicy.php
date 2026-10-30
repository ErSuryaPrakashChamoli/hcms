<?php

namespace App\Domain\Recognition\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\OwnRecords;
use App\Domain\Recognition\Models\RewardPointEntry;
use App\Domain\Recognition\Services\RewardPoints;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne setup parity HP-9d: the reward points ledger. Employees (recognition.view) see their own rows; HR with
 * recognition.points sees the budget and every employee's rows within their access scope. Rows are written only by
 * RewardPoints and never edited or deleted.
 */
class RewardPointEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('recognition.points') || $user->hasPermission('recognition.view') || $user->hasPermission('recognition.give');
    }

    public function view(User $user, Model $model): bool
    {
        if (! $model instanceof RewardPointEntry) {
            return false;
        }
        if ($model->employee_id === null) {
            return $user->hasPermission('recognition.points');
        }

        return app(OwnRecords::class)->isOwn($user, (int) $model->employee_id)
            || ($user->hasPermission('recognition.points') && app(RewardPoints::class)->manages($user, $model->employee()->withoutGlobalScopes()->firstOrFail()));
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
}
