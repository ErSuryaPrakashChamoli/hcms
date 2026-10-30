<?php

namespace App\Domain\Identity\Jobs;

use App\Domain\Identity\Services\RoleMembershipSync;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * HROne parity SV-7: applies the tenant's role membership rules to one employee (after a hire, a move or an exit) or to
 * everyone. Tenant-bound (BindTenantContext), safe to repeat (the run compares and changes only what differs).
 */
class SyncRoleMemberships implements ShouldQueue, TenantAwareJob
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public ?int $tenantId;

    /** @param  list<int>|null  $employeeIds */
    public function __construct(public ?array $employeeIds = null)
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

    public function handle(RoleMembershipSync $sync): void
    {
        $sync->run($this->employeeIds);
    }
}
