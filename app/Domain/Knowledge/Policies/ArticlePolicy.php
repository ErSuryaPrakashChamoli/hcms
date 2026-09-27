<?php

namespace App\Domain\Knowledge\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

class ArticlePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('kb.manage') || $user->hasPermission('kb.view');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission('kb.manage') || ($user->hasPermission('kb.view') && $model->getAttribute('status') === 'published');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('kb.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('kb.manage');
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->hasPermission('kb.manage') && $model->getAttribute('status') === 'draft';
    }
}
