<?php

namespace App\Domain\Notifications\Channels;

use App\Domain\Notifications\Models\NotificationDelivery;
use Illuminate\Support\Facades\Log;

/** Placeholder for SMS / WhatsApp / push until the Integration Hub connects real providers. */
final class LogChannel implements Channel
{
    public function send(NotificationDelivery $delivery): void
    {
        Log::info("[notification:{$delivery->channel}] to user {$delivery->user_id}: {$delivery->subject}");
    }
}
