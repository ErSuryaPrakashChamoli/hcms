<?php

namespace App\Domain\Notifications\Channels;

use App\Domain\Notifications\Mail\NotificationMail;
use App\Domain\Notifications\Models\NotificationDelivery;
use Illuminate\Support\Facades\Mail;

final class EmailChannel implements Channel
{
    public function send(NotificationDelivery $delivery): void
    {
        $address = $delivery->recipient ?: $delivery->user?->email ?? throw new \RuntimeException('No email address for delivery.');

        Mail::to($address)->send(new NotificationMail($delivery->subject, $delivery->body));
    }
}
