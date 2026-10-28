<?php

namespace App\Domain\Compliance\Policies;

use App\Domain\Compliance\Models\EstablishmentStatutoryProfile;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use Illuminate\Database\Eloquent\Model;

/**
 * Registrations and establishment statutory profiles: view with compliance.registrations.view, change with .manage.
 * Registrations are never deleted; a closed establishment profile may be (the model refuses one payroll used).
 */
class StatutoryRegistrationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('compliance.registrations.view') || $user->hasPermission('compliance.registrations.manage');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user) && app(AccessScopes::class)->allows($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('compliance.registrations.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('compliance.registrations.manage') && app(AccessScopes::class)->allows($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return $model instanceof EstablishmentStatutoryProfile && $model->effective_to !== null && $this->update($user, $model);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
