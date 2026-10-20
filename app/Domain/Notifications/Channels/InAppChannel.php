<?php

namespace App\Domain\Notifications\Channels;

use App\Domain\Experience\Support\NotificationCategories;
use App\Domain\Notifications\Models\NotificationDelivery;
use Filament\Notifications\Notification;

/** Filament's database notifications: the bell in the Admin Control Centre. */
final class InAppChannel implements Channel
{
    public function send(NotificationDelivery $delivery): void
    {
        $user = $delivery->user ?? throw new \RuntimeException('In-app notifications need a user.');

        // Phase 14: stored now (Filament's database notification is queued by default), so "sent" is true.
        // Experience Transformation: presentation metadata for the notification center (group, deep link,
        // icon). Delivery, recipients and content are unchanged.
        $group = NotificationCategories::for($delivery->event);
        [, $icon, $color] = NotificationCategories::meta($group);

        $user->notifyNow(Notification::make()->title($delivery->subject)->body($delivery->body)->icon($icon)->iconColor($color)
            ->viewData(['peopleos' => ['group' => $group, 'event' => $delivery->event, 'source_type' => $delivery->source_type, 'source_id' => $delivery->source_id, 'delivery_id' => $delivery->id]])
            ->toDatabase());
    }
}
