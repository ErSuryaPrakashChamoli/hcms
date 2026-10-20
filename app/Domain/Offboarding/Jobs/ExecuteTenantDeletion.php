<?php

namespace App\Domain\Offboarding\Jobs;

use App\Domain\Offboarding\Services\DeletionExecutor;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\RunsForSuspendedTenants;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * SaaS.9: executes one approved, due tenant deletion (DeletionExecutor). Runs for the closed tenant it names
 * (RunsForSuspendedTenants), only when deletion is enabled; the deletion is claimed with a conditional update, so a
 * duplicate job does nothing. One attempt: a failure is recorded on the deletion and an operator retries it (resumes).
 */
class ExecuteTenantDeletion implements RunsForSuspendedTenants, ShouldQueue, TenantAwareJob
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly int $deletionId, public ?int $tenantId) {}

    public function tenantId(): ?int
    {
        return $this->tenantId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new BindTenantContext];
    }

    public function handle(DeletionExecutor $executor): void
    {
        $executor->execute($this->deletionId);
    }
}
