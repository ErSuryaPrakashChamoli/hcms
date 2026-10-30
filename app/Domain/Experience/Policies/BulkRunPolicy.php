<?php

namespace App\Domain\Experience\Policies;

use App\Domain\Experience\Models\BulkRun;
use App\Domain\Experience\Models\BulkRunItem;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity WO-1: a person sees the bulk runs they started (bulk.run); bulk.view_all sees every run of the
 * organisation. Runs are written only by BulkRuns: nobody creates, edits or deletes one from a screen.
 */
class BulkRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('bulk.run') || $user->hasPermission('bulk.view_all');
    }

    public function view(User $user, Model $model): bool
    {
        $run = $model instanceof BulkRunItem ? $model->run : $model;

        return $run instanceof BulkRun && ($user->hasPermission('bulk.view_all') || ((int) $run->user_id === (int) $user->id && $user->hasPermission('bulk.run')));
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Model $model): bool
    {
        return false;
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
