<?php

namespace App\Domain\Integration\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Integration\Models\InboundEvent;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 14: integration systems, external references, mappings and inbound events. Changes go through
 * the Integration Hub services; inbound events are never created or edited by hand, and nothing here is
 * deleted (systems and references are retired).
 */
class IntegrationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('integration.view') || $user->hasPermission('integration.manage');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('integration.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('integration.manage') && ! ($model instanceof InboundEvent);
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
