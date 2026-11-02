<?php

namespace App\Domain\Engagement\Policies;

use App\Domain\Engagement\Models\CompanyEvent;
use App\Domain\Engagement\Services\CompanyEvents;
use App\Domain\Identity\Models\User;

/**
 * HROne parity GO-15: company events are run by engagement.events; the host and the people invited open the events they
 * belong to (CompanyEvents::canView). Events are never deleted — they are cancelled with a reason.
 */
class CompanyEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('engagement.events');
    }

    public function view(User $user, CompanyEvent $event): bool
    {
        return app(CompanyEvents::class)->canView($user, $event);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('engagement.events');
    }

    public function update(User $user, CompanyEvent $event): bool
    {
        return $user->hasPermission('engagement.events') && in_array($event->status, ['draft', 'published'], true);
    }

    public function delete(User $user, CompanyEvent $event): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, CompanyEvent $event): bool
    {
        return false;
    }

    public function forceDelete(User $user, CompanyEvent $event): bool
    {
        return false;
    }
}
