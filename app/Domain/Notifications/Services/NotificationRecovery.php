<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Jobs\DeliverNotification;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * SaaS.8: deliveries that would otherwise wait forever, and an administrator's audited resend.
 *
 * A delivery is claimed (queued → sending) before its channel is called. If the worker died between the claim and the
 * outcome, the delivery stayed `sending` for ever; if its job was lost (a dropped or deleted queue entry), it stayed
 * `queued` for ever; and a failed delivery could not be sent again at all.
 *
 * - recover() (bound tenant; peopleos:notifications:recover every five minutes):
 *   - `sending` past the lease → `failed`, outcome unknown. Never sent again automatically: it may have been received.
 *   - async `queued` past the requeue window → dispatched again. Each row is touched with a conditional update first, so
 *     two sweeps dispatch it once; the delivery claim makes a duplicate job harmless anyway.
 * - resend(): a person holding notification.resend sends a failed delivery again, with a reason. failed → queued is a
 *   conditional update, so two administrators resending at once produce one resend and one audit event.
 */
final class NotificationRecovery
{
    public const UNKNOWN_OUTCOME = 'Outcome unknown: the worker stopped while sending. Check it was not received before sending it again.';

    public function __construct(private readonly AuditRecorder $audit, private readonly TenantContext $tenants, private readonly Notifier $notifier) {}

    /** @return array{stale_sending: int, requeued: int} */
    public function recover(): array
    {
        if (! $this->tenants->has()) {
            throw new RuntimeException('Notification recovery runs for one tenant at a time.');
        }
        $lease = now()->subMinutes((int) config('peopleos.notifications.sending_lease_minutes', 15));
        $stale = NotificationDelivery::query()->where('status', 'sending')->where('updated_at', '<', $lease)
            ->update(['status' => 'failed', 'error' => self::UNKNOWN_OUTCOME, 'updated_at' => now()]);

        $window = now()->subMinutes((int) config('peopleos.notifications.requeue_after_minutes', 60));
        $async = (array) config('peopleos.notifications.async_channels', []);
        $requeued = 0;
        NotificationDelivery::query()->where('status', 'queued')->where('updated_at', '<', $window)->whereIn('channel', $async)
            ->orderBy('id')->limit(500)->pluck('id')
            ->each(function (int $id) use ($window, &$requeued) {
                $touched = NotificationDelivery::query()->whereKey($id)->where('status', 'queued')->where('updated_at', '<', $window)->update(['updated_at' => now()]);
                if ($touched === 1) {
                    DeliverNotification::dispatch($id);
                    $requeued++;
                }
            });

        return ['stale_sending' => $stale, 'requeued' => $requeued];
    }

    public function resend(NotificationDelivery $delivery, ?User $actor, ?string $reason): NotificationDelivery
    {
        if ($actor === null || ! $this->tenants->has() || (int) $delivery->tenant_id !== $this->tenants->id() || ! $actor->can('resend', $delivery)) {
            throw new RuntimeException('You cannot send this notification again.');
        }
        $reason = trim((string) $reason);
        if (Str::length($reason) < 5 || Str::length($reason) > 500) {
            throw new RuntimeException('A reason of 5 to 500 characters is required.');
        }

        $moved = DB::transaction(function () use ($delivery, $actor, $reason) {
            $rows = NotificationDelivery::query()->whereKey($delivery->getKey())->where('status', 'failed')
                ->update(['status' => 'queued', 'error' => null, 'resend_count' => DB::raw('resend_count + 1'), 'updated_at' => now()]);
            if ($rows !== 1) {
                return false;
            }
            $fresh = $delivery->fresh();
            $this->audit->record(AuditAction::NotificationResent, 'notifications', $fresh, [['field' => 'status', 'before' => 'failed', 'after' => 'queued']], $reason,
                entityLabel: "Notification #{$fresh->getKey()}", actor: $actor, metadata: ['channel' => $fresh->channel, 'event' => $fresh->event, 'resend_count' => $fresh->resend_count]);

            return true;
        });
        if (! $moved) {
            throw new RuntimeException('This notification is no longer failed (it was already sent again).');
        }

        $delivery->refresh();
        if (in_array($delivery->channel, (array) config('peopleos.notifications.async_channels', []), true)) {
            DeliverNotification::dispatch($delivery->getKey())->afterCommit();
        } else {
            $this->notifier->deliver($delivery);
        }

        return $delivery->refresh();
    }
}
