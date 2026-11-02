<?php

namespace App\Domain\Engagement\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity GO-15: sessions, invitations and attendance of company events are read by whoever runs events (in their
 * access scope) and written only through CompanyEvents (publish, respond, nominate, markAttendance) — never directly.
 */
class CompanyEventRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('engagement.events');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission('engagement.events');
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

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
