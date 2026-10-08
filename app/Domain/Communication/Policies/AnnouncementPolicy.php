<?php

namespace App\Domain\Communication\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Phase 13: drafts are edited by communication.manage; reviewers see what is submitted; readers see published items only. */
class AnnouncementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('communication.manage') || $user->hasPermission('communication.approve') || $user->hasPermission('communication.view');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission('communication.manage') || $user->hasPermission('communication.approve')
            || ($user->hasPermission('communication.view') && $model->getAttribute('status') === 'published');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('communication.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('communication.manage') && $model->getAttribute('status') === 'draft';
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->hasPermission('communication.manage') && $model->getAttribute('status') === 'draft';
    }
}
