<?php

namespace App\Domain\Engagement\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 13: surveys, versions, questions, audiences and campaigns (configuration — never responses).
 * Changes go through the engagement services (lifecycle, separation of duties); nothing is deleted.
 */
class EngagementConfigPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('engagement.view') || $user->hasPermission('engagement.manage') || $user->hasPermission('engagement.approve') || $user->hasPermission('engagement.analytics');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('engagement.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('engagement.manage') && in_array($model->getAttribute('status'), [null, 'draft', 'active'], true);
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
