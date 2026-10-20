<?php

namespace App\Domain\Platform\Listeners;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Services\PlatformTenantAccess;
use Illuminate\Auth\Events\Logout;

/** SaaS.2: signing out ends a platform operator's tenant access, and the end is audited. */
final class EndPlatformAccessOnLogout
{
    public function __construct(private readonly PlatformTenantAccess $access) {}

    public function handle(Logout $event): void
    {
        if ($event->user instanceof User && $event->user->isPlatformAdmin() && request()->hasSession()) {
            $this->access->end($event->user, request()->session(), 'sign_out');
        }
    }
}
