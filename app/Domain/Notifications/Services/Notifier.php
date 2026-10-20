<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Channels\Channel;
use App\Domain\Notifications\Jobs\DeliverNotification;
use App\Domain\Notifications\Models\NotificationDelivery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Throwable;

/**
 * Creates deliveries and pushes them through channel drivers, recording the outcome.
 *
 * Phase 14:
 * - In-app messages are stored at once, so "sent" means the bell has them.
 * - External channels (email, SMS, ...) are delivered by a tenant-bound job after the business
 *   transaction commits; no mail server is waited on inside a transaction.
 * - Every delivery carries the correlation id of the request or command run, plus a dedupe key: the
 *   same message to the same person on the same channel for the same source, within one operation
 *   (including a retried job), is recorded once.
 */
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
        $correlation = Context::get('request_id');
        $async = config('peopleos.notifications.async_channels', ['email', 'sms', 'whatsapp', 'push']);

        foreach ($users as $user) {
            foreach ($channels as $channel) {
                if (! config("peopleos.notifications.channels.{$channel}")) {
                    continue;
                }
                $dedupe = $correlation === null ? null
                    : hash('sha256', implode('|', [$user->id, $channel, $event, $source?->getMorphClass(), $source?->getKey(), $subject, $body, $correlation]));
                if ($dedupe !== null && NotificationDelivery::query()->where('dedupe_key', $dedupe)->exists()) {
                    continue;
                }

                try {
                    $delivery = NotificationDelivery::create([
                        'user_id' => $user->id,
                        'channel' => $channel,
                        'event' => $event,
                        'subject' => $subject,
                        'body' => $body,
                        'status' => 'queued',
                        'recipient' => $channel === 'email' ? $user->email : null,
                        'source_type' => $source?->getMorphClass(),
                        'source_id' => $source?->getKey(),
                        'correlation_id' => $correlation,
                        'dedupe_key' => $dedupe,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    continue;
                }

                if (in_array($channel, $async, true)) {
                    DeliverNotification::dispatch($delivery->id)->afterCommit();
                } else {
                    $this->deliver($delivery);
                }
                $deliveries->push($delivery);
            }
        }

        return $deliveries;
    }

    /**
     * Push one delivery through its channel. The delivery is claimed first (queued → sending), so it is
     * never sent twice. On failure: failed when $final, otherwise back to queued for a retry.
     */
    public function deliver(NotificationDelivery $delivery, bool $final = true): bool
    {
        $claimed = NotificationDelivery::query()->whereKey($delivery->id)->whereIn('status', ['queued', 'failed'])->update(['status' => 'sending', 'updated_at' => now()]);
        if ($claimed !== 1) {
            return true;
        }

        /** @var Channel $driver */
        $driver = app(config("peopleos.notifications.channels.{$delivery->channel}.driver"));

        try {
            $driver->send($delivery->refresh());
            $delivery->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);

            return true;
        } catch (Throwable $e) {
            report($e);
            $delivery->update(['status' => $final ? 'failed' : 'queued', 'error' => mb_substr($e->getMessage(), 0, 255)]);

            return false;
        }
    }
}
