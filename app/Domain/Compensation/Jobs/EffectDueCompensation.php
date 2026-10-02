<?php

namespace App\Domain\Compensation\Jobs;

use App\Domain\Compensation\Services\CompensationChanges;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Phase 11: the effective-date processor for one tenant — tenant-bound, unique per tenant, and safe to
 * repeat (each change is locked and re-checked, so it becomes effective exactly once).
 */
class EffectDueCompensation implements ShouldBeUnique, ShouldQueue, TenantAwareJob
{
    use Queueable;

    public int $tries = 2;

    public ?int $tenantId;

    public function __construct(public ?string $on = null)
    {
        $this->tenantId = app(TenantContext::class)->id();
    }

    public function tenantId(): ?int
    {
        return $this->tenantId;
    }

    public function uniqueId(): string
    {
        return 'compensation-effect-'.$this->tenantId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new BindTenantContext];
    }

    public function handle(CompensationChanges $changes): void
    {
        $changes->effectDue($this->on);
    }
}
