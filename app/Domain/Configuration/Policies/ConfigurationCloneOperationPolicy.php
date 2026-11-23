<?php

namespace App\Domain\Configuration\Policies;

use App\Domain\Configuration\Cloning\CloneScope;
use App\Domain\Configuration\Models\ConfigurationCloneOperation;
use App\Domain\Identity\Models\User;

/**
 * Clone history (configuration cloning): read-only, for whoever may see the Change Centre or may clone. A person limited
 * to some companies sees only the clones they made or that wrote into their companies. Nobody edits or deletes it.
 */
class ConfigurationCloneOperationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('configuration.view') || $user->hasPermission('configuration.clone');
    }

    public function view(User $user, ConfigurationCloneOperation $operation): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }
        $mine = app(CloneScope::class)->companiesFor($user);

        return $mine === null || (int) $operation->initiated_by === (int) $user->id
            || array_intersect(array_map('intval', (array) $operation->destination_company_ids), $mine) !== [];
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ConfigurationCloneOperation $operation): bool
    {
        return false;
    }

    public function delete(User $user, ConfigurationCloneOperation $operation): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
