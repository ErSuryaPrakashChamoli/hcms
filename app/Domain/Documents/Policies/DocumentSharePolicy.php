<?php

namespace App\Domain\Documents\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity WO-14: document share batches and their items (the shared documents) are for people who share documents
 * (`document.share`: HROne's rights View and Unpublish). They are written only by DocumentShares; nothing is edited or
 * deleted here (unpublishing hides a document, D-WO5). Items stay inside the viewer's access scope (ScopedByEmployee).
 */
class DocumentSharePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('document.share');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission('document.share');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('document.share');
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
