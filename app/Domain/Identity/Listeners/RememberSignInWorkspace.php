<?php

namespace App\Domain\Identity\Listeners;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\RememberedWorkspace;
use Illuminate\Auth\Events\Login;

/**
 * Branded sign-in: every sign-in of an organisation's person (password, remember-me cookie or SSO) remembers the
 * organisation on this browser, so its sign-in page shows the organisation's look next time. Platform operators belong
 * to no organisation and leave the browser as it is.
 */
final class RememberSignInWorkspace
{
    public function __construct(private readonly RememberedWorkspace $workspace) {}

    public function handle(Login $event): void
    {
        if (! $event->user instanceof User || $event->user->tenant_id === null) {
            return;
        }
        $tenant = $event->user->tenant;
        if ($tenant !== null) {
            $this->workspace->remember($tenant);
        }
    }
}
