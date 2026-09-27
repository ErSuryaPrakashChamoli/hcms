<?php

namespace Tests\Support;

use App\Domain\Organisation\Models\Company;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Test double for the tenant-aware job pattern (Phase 0.2). */
class TenantAwareTestJob implements ShouldQueue, TenantAwareJob
{
    use Queueable;

    public ?int $tenantId;

    public function __construct(public string $companyName)
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

    public function handle(): void
    {
        Company::factory()->create(['name' => $this->companyName]);
    }
}
