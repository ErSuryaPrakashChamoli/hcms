<?php

namespace App\Domain\Experience\Jobs;

use App\Domain\Experience\Models\BulkRun;
use App\Domain\Experience\Services\BulkRuns;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * HROne parity WO-1: runs one bulk run (BulkRuns::execute) in the tenant it was started in. A retry continues where the
 * previous attempt stopped: records that already have an outcome are skipped, so nothing is done twice.
 */
class RunBulkOperation implements ShouldQueue, TenantAwareJob
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 1800;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public ?int $tenantId;

    public function __construct(public readonly int $bulkRunId)
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

    public function handle(BulkRuns $runs): void
    {
        $run = BulkRun::query()->find($this->bulkRunId);
        if ($run !== null) {
            $runs->execute($run);
        }
    }

    public function failed(?Throwable $e): void
    {
        $context = app(TenantContext::class);
        // Tenants are not tenant-scoped: the run's tenant is found directly, then bound for the update.
        $tenant = $this->tenantId === null ? null : Tenant::query()->find($this->tenantId);
        if ($tenant === null) {
            return;
        }
        $context->runAs($tenant, fn () => BulkRun::query()->whereKey($this->bulkRunId)->whereIn('status', ['queued', 'running'])->first()
            ?->update(['status' => 'failed', 'error' => mb_substr('The run stopped: '.($e?->getMessage() ?? 'unknown error'), 0, 500), 'finished_at' => now()]));
    }
}
