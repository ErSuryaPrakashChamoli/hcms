<?php

namespace App\Domain\Learning\Jobs;

use App\Domain\Learning\Services\LearningReminders;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Phase 8: the learning reminder run for one tenant — tenant-bound, unique per tenant, idempotent (reminder log). */
class SendLearningReminders implements ShouldBeUnique, ShouldQueue, TenantAwareJob
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
        return 'learning-reminders-'.$this->tenantId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new BindTenantContext];
    }

    public function handle(LearningReminders $reminders): void
    {
        $reminders->tick();
    }
}
