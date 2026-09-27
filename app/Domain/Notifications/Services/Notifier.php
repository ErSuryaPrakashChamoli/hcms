<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Channels\Channel;
use App\Domain\Notifications\Models\NotificationDelivery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;

/** Creates deliveries and pushes them through channel drivers, recording the outcome. */
final class Notifier
{
    /**
     * @param  iterable<int, User>  $users
     * @param  list<string>  $channels
     * @return Collection<int, NotificationDelivery>
     */
    public function send(iterable $users, array $channels, string $subject, string $body, ?string $event = null, ?Model $source = null): Collection
    {
        $deliveries = Collection::make();

        foreach ($users as $user) {
            foreach ($channels as $channel) {
                if (! config("peopleos.notifications.channels.{$channel}")) {
                    continue;
                }

                $delivery = NotificationDelivery::create([
                    'user_id' => $user->id,
                    'channel' => $channel,
                    'event' => $event,
                    'subject' => $subject,
                    'body' => $body,
                    'recipient' => $channel === 'email' ? $user->email : null,
                    'source_type' => $source?->getMorphClass(),
                    'source_id' => $source?->getKey(),
                ]);

                $this->deliver($delivery);
                $deliveries->push($delivery);
            }
        }

        return $deliveries;
    }

    public function deliver(NotificationDelivery $delivery): void
    {
        /** @var Channel $driver */
        $driver = app(config("peopleos.notifications.channels.{$delivery->channel}.driver"));

        try {
            $driver->send($delivery);
            $delivery->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);
        } catch (Throwable $e) {
            report($e);
            $delivery->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 255)]);
        }
    }
}
