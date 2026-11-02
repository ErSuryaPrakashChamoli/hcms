<?php

namespace App\Domain\Leave\Jobs;

use App\Domain\Leave\Models\LeaveEncashmentBatch;
use App\Domain\Leave\Services\LeaveEncashmentBatches;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * HROne parity PO-8: creates an encashment batch's encashments once it is approved (or at once when no approval is asked
 * for). Unique per batch, tenant-bound and safe to retry: an employee already encashed by the batch is not encashed again,
 * and a batch that is no longer processing is left as it is.
 */
class ProcessLeaveEncashmentBatch implements ShouldBeUnique, ShouldQueue, TenantAwareJob
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 1800;

    public ?int $tenantId;

    public function __construct(public readonly int $batchId)
    {
        $this->tenantId = app(TenantContext::class)->id();
    }

    public function uniqueId(): string
    {
        return "leave-encashment-batch-{$this->tenantId}-{$this->batchId}";
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

    public function handle(LeaveEncashmentBatches $batches): void
    {
        $batch = LeaveEncashmentBatch::query()->find($this->batchId);
        if ($batch !== null && $batch->status === 'processing') {
            $batches->process($batch);
        }
    }
}
