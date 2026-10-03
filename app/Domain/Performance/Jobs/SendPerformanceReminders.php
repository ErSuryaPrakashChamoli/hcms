<?php

namespace App\Domain\Performance\Jobs;

use App\Domain\Performance\Services\PerformanceReminders;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Phase 7: the performance reminder run for one tenant — tenant-bound, unique per tenant, retry-safe (reminder log). */
class SendPerformanceReminders implements ShouldBeUnique, ShouldQueue, TenantAwareJob
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    /** @var list<int> Phase 14: retry with backoff instead of hammering a failing dependency. */
    public array $backoff = [60];

    public ?int $tenantId;

    public function __construct()
    {
        $this->tenantId = app(TenantContext::class)->id();
    }

    public function tenantId(): ?int
    {
        return $this->tenantId;
    }

    public function uniqueId(): string
    {
        return 'performance-reminders-'.$this->tenantId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new BindTenantContext];
    }

    public function handle(PerformanceReminders $reminders): void
    {
        $reminders->tick();
    }
}
