<?php

namespace App\Domain\ProductFeedback\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\PermissionPolicy;
use App\Domain\ProductFeedback\Models\ProductIdea;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity AC-3: the organisation's product ideas are for whoever holds product.feedback in it (the tenant scope keeps
 * other organisations' ideas out). Suggesting one is product.feedback too, through ProductIdeas::submit. Nobody edits or
 * deletes an idea through a policy: once sent it is Markedge's to answer (ProductIdeas::changeStatus, operators only).
 */
class ProductIdeaPolicy extends PermissionPolicy
{
    public const PERMISSION = 'product.feedback';

    protected string $resource = 'product';

    public function viewAny(User $user): bool
    {
        return $user->hasPermission(self::PERMISSION);
    }

    public function view(User $user, Model $model): bool
    {
        return $model instanceof ProductIdea && $user->hasPermission(self::PERMISSION)
            && (int) $model->getAttribute('tenant_id') === (int) $user->getAttribute('tenant_id');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(self::PERMISSION);
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

    public function restore(User $user, Model $model): bool
    {
        return false;
    }
}
