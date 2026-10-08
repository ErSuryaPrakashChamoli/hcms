<?php

namespace App\Domain\Knowledge\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Phase 12: writers (kb.manage) and reviewers (kb.review) see working copies; readers only published, non-archived articles. */
class ArticlePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('kb.manage') || $user->hasPermission('kb.review') || $user->hasPermission('kb.view');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission('kb.manage') || ($user->hasPermission('kb.review') && $model->getAttribute('status') === 'in_review')
            || ($user->hasPermission('kb.view') && $model->getAttribute('published_version') !== null && $model->getAttribute('status') !== 'archived');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('kb.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('kb.manage') && $model->getAttribute('status') === 'draft';
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->hasPermission('kb.manage') && $model->getAttribute('status') === 'draft' && $model->getAttribute('published_version') === null;
    }
}
