<?php

namespace App\Domain\Recognition\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Recognition\Models\Recognition;
use App\Domain\Recognition\Services\Recognitions;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne setup parity HP-9c: the recognitions ledger. Anyone with recognition.view sees public badges; a private one is
 * seen by its receiver, their manager, its giver and HR (Recognitions::visibleTo). Badges are given only through
 * Recognitions::give() and never edited or deleted; HR withdraws one there.
 */
class RecognitionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('recognition.view') || $user->hasPermission('recognition.give') || $user->hasPermission('recognition.manage');
    }

    public function view(User $user, Model $model): bool
    {
        return $model instanceof Recognition && $this->viewAny($user) && app(Recognitions::class)->canSee($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('recognition.give');
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
}
