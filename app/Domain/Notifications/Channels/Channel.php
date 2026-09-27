<?php

namespace App\Domain\Notifications\Channels;

use App\Domain\Notifications\Models\NotificationDelivery;

interface Channel
{
    /** Deliver; throw on failure so the Notifier can record it. */
    public function send(NotificationDelivery $delivery): void;
}
