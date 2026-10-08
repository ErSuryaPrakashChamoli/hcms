<?php

namespace App\Domain\Talent\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Talent review sessions: talent.view / talent.manage see all; a participant with talent.review sees only the sessions they take part in. */
class TalentReviewPolicy extends TalentConfigPolicy
{
    public function viewAny(User $user): bool
    {
        return parent::viewAny($user) || $user->hasPermission('talent.review');
    }

    public function view(User $user, Model $model): bool
    {
        if (parent::viewAny($user)) {
            return true;
        }
        $participants = array_map('intval', (array) ($model->getAttribute('participants') ?? []));

        return $user->hasPermission('talent.review') && in_array((int) $user->id, $participants, true);
    }
}
