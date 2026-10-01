<?php

namespace App\Domain\Talent\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Talent pools, assessment models and versions, talent review sessions: talent.view to read, talent.manage to configure. */
class TalentConfigPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('talent.view') || $user->hasPermission('talent.manage') || $user->hasPermission('talent.review');
    }

    public function view(User $user, Model $model): bool
    {
        if ($user->hasPermission('talent.view') || $user->hasPermission('talent.manage')) {
            return true;
        }
        // A review participant sees the session they take part in.
        $participants = (array) ($model->getAttribute('participants') ?? []);

        return $user->hasPermission('talent.review') && in_array((int) $user->id, array_map('intval', $participants), true);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('talent.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('talent.manage');
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
