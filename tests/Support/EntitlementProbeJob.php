<?php

namespace Tests\Support;

use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Services\Entitlements;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** SaaS.3 test double: a tenant-aware job that observes an entitlement and remembers what it saw. */
class EntitlementProbeJob implements ShouldQueue, TenantAwareJob
{
    use Queueable;

    /** @var list<array<string, mixed>> */
    public static array $seen = [];

    public ?int $tenantId;

    public function __construct()
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
        self::$seen[] = app(Entitlements::class)->observe(Capability::Payroll, 'test.probe')->toArray();
    }
}
