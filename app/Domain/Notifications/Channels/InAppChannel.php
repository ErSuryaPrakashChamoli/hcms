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

        Notification::make()
            ->title($delivery->subject)
            ->body($delivery->body)
            ->sendToDatabase($user);
    }
}
