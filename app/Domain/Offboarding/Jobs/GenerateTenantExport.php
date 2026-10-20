<?php

namespace App\Domain\Offboarding\Jobs;

use App\Domain\Offboarding\Services\TenantExports;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\RunsForSuspendedTenants;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * SaaS.9: generates one tenant export package. Runs for closed and suspended tenants too (it is how their data leaves;
 * RunsForSuspendedTenants), bound to the export's tenant. Idempotent: the export is claimed with a conditional update, so a
 * duplicate job does nothing; a stopped worker is replaced by the sweep and resumes from the checkpoint.
 */
class GenerateTenantExport implements RunsForSuspendedTenants, ShouldQueue, TenantAwareJob
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly int $exportId, public ?int $tenantId) {}

    public function tenantId(): ?int
    {
        return $this->tenantId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new BindTenantContext];
    }

    public function handle(TenantExports $exports): void
    {
        $exports->generate($this->exportId);
    }
}
