<?php

namespace App\Domain\Partners\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\PermissionPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity WO-15: business partners and vendors. `partner.view` lists and opens them, `partner.manage` adds and edits
 * them (and sees them). Nothing is deleted: partners and vendors are inactivated. Inviting a partner and disabling their
 * login also need the user permissions (BusinessPartners checks them).
 */
class PartnerPolicy extends PermissionPolicy
{
    protected string $resource = 'partner';

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('partner.view') || $user->hasPermission('partner.manage');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('partner.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('partner.manage');
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Model $model): bool
    {
        return false;
    }
}
