<?php

namespace App\Domain\Notifications\Jobs;

use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Notifications\Services\Notifier;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;

/**
 * Phase 14: delivers one external-channel notification (email, SMS, ...) outside the business
 * transaction. It is dispatched after commit, so a rolled-back action never sends anything. The
 * delivery is claimed (queued → sending), so a duplicate job never sends twice. A failure goes back to
 * queued and is retried with backoff; the last attempt records it as failed.
 */
class DeliverNotification implements ShouldQueue, TenantAwareJob
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public ?int $tenantId;

    public function __construct(public readonly int $deliveryId)
    {
        $this->tenantId = app(TenantContext::class)->id();
    }

    public function tenantId(): ?int
    {
        return $this->tenantId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new BindTenantContext];
    }

    public function handle(Notifier $notifier): void
    {
        $delivery = NotificationDelivery::query()->find($this->deliveryId);
        if ($delivery === null || $delivery->status !== 'queued') {
            return;
        }
        $final = $this->job instanceof SyncJob || $this->job === null || $this->attempts() >= $this->tries;
        if (! $notifier->deliver($delivery, $final)) {
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)] ?? 60);
        }
    }
}
