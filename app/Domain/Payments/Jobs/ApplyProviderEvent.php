<?php

namespace App\Domain\Payments\Jobs;

use App\Domain\Payments\Services\ProviderEvents;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\RunsForSuspendedTenants;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * SaaS.7: applies one verified provider event to its payment, bound to the tenant resolved from the verified
 * provider reference. Unique per event and idempotent (leased claim; the reconciler ignores repeats), so a
 * retried or duplicated job never records a payment twice. It runs for suspended tenants too: the money already
 * moved, and only commercial records are touched.
 */
class ApplyProviderEvent implements RunsForSuspendedTenants, ShouldBeUnique, ShouldQueue, TenantAwareJob
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public ?int $tenantId, public int $providerEventId) {}

    public function tenantId(): ?int
    {
        return $this->tenantId;
    }

    public function uniqueId(): string
    {
        return 'provider-event-'.$this->providerEventId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new BindTenantContext];
    }

    public function handle(ProviderEvents $events): void
    {
        $events->apply($this->providerEventId);
    }
}
