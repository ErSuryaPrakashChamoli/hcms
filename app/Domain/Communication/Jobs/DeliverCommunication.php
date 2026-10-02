<?php

namespace App\Domain\Communication\Jobs;

use App\Domain\Communication\Models\Announcement;
use App\Domain\Communication\Services\CommunicationDelivery;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Phase 13: delivers one published announcement in batches until no recipient is due — tenant-bound,
 * unique per announcement. Each recipient is claimed under a lock, so even overlapping workers never
 * notify anyone twice.
 */
class DeliverCommunication implements ShouldBeUnique, ShouldQueue, TenantAwareJob
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public ?int $tenantId, public int $announcementId) {}

    public function tenantId(): ?int
    {
        return $this->tenantId;
    }

    public function uniqueId(): string
    {
        return 'communication-delivery-'.$this->announcementId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new BindTenantContext];
    }

    public function handle(CommunicationDelivery $delivery): void
    {
        $announcement = Announcement::query()->find($this->announcementId);
        if ($announcement === null) {
            return;
        }
        // New recipients, batch by batch (bounded). Failures are retried later by the scheduled
        // communication run, not in this loop.
        for ($pass = 0; $pass < 1000; $pass++) {
            $counts = $delivery->deliver($announcement, retryFailed: false);
            if (array_sum($counts) === 0 || $delivery->pending($announcement, retryFailed: false) === 0) {
                return;
            }
        }
    }
}
