<?php

namespace App\Domain\Integration\Jobs;

use App\Domain\Integration\Models\InboundEvent;
use App\Domain\Integration\Services\InboundEvents;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Phase 14: applies one inbound integration event — tenant-bound, unique per event, and idempotent
 * (the claim is a conditional update; a repeated or concurrent run finds nothing to do). Retries and
 * dead-lettering are the event's own state machine, not queue retries.
 */
class ProcessInboundEvent implements ShouldBeUnique, ShouldQueue, TenantAwareJob
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public ?int $tenantId, public int $inboundEventId) {}

    public function tenantId(): ?int
    {
        return $this->tenantId;
    }

    public function uniqueId(): string
    {
        return 'inbound-event-'.$this->tenantId.'-'.$this->inboundEventId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new BindTenantContext];
    }

    public function handle(InboundEvents $events): void
    {
        $event = InboundEvent::query()->find($this->inboundEventId);
        if ($event !== null && ! $event->isTerminal()) {
            $events->process($event);
        }
    }
}
