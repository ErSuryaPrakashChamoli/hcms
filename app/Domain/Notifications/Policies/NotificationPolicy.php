<?php

namespace App\Domain\Notifications\Policies;

use App\Domain\Identity\Policies\PermissionPolicy;

class NotificationPolicy extends PermissionPolicy
{
    protected string $resource = 'notification';
}
