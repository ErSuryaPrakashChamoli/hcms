<?php

namespace App\Domain\Notifications\Channels;

use App\Domain\Notifications\Models\NotificationDelivery;
use Filament\Notifications\Notification;

/** Filament's database notifications: the bell in the Admin Control Centre. */
final class InAppChannel implements Channel
{
    public function send(NotificationDelivery $delivery): void
    {
        $user = $delivery->user ?? throw new \RuntimeException('In-app notifications need a user.');

        // Phase 14: stored now (Filament's database notification is queued by default), so "sent" is true.
        $user->notifyNow(Notification::make()->title($delivery->subject)->body($delivery->body)->toDatabase());
    }
}
