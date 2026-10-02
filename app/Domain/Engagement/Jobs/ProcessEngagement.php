<?php

namespace App\Domain\Engagement\Jobs;

use App\Domain\Engagement\Services\EngagementProcessor;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Phase 13: the engagement run for one tenant (open / close surveys, invitations, reminders, campaigns) — tenant-bound, unique, idempotent. */
class ProcessEngagement implements ShouldBeUnique, ShouldQueue, TenantAwareJob
{
    use Queueable;

    public int $tries = 2;

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
        return 'engagement-process-'.$this->tenantId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new BindTenantContext];
    }

    public function handle(EngagementProcessor $processor): void
    {
        $processor->run();
    }
}
