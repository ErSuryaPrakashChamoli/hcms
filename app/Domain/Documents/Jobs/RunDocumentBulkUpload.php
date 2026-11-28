<?php

namespace App\Domain\Documents\Jobs;

use App\Domain\Documents\Models\DocumentUploadBatch;
use App\Domain\Documents\Services\DocumentBulkUploads;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs one bulk upload of employee documents (DocumentBulkUploads::run) in the tenant it was started in. A retry after a
 * crash finds the upload running and carries on with the files still ready (each file is uploaded once).
 */
class RunDocumentBulkUpload implements ShouldQueue, TenantAwareJob
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 1800;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public ?int $tenantId;

    public function __construct(public readonly int $batchId)
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

    public function handle(DocumentBulkUploads $uploads): void
    {
        $batch = DocumentUploadBatch::query()->find($this->batchId);
        if ($batch !== null) {
            if ($batch->status === 'running' && $this->attempts() > 1) {
                $batch->withoutAuditing()->update(['status' => 'queued']);
            }
            $uploads->run($batch);
        }
    }

    public function failed(?Throwable $e): void
    {
        $tenant = $this->tenantId === null ? null : Tenant::query()->find($this->tenantId);
        if ($tenant !== null) {
            app(TenantContext::class)->runAs($tenant, fn () => app(DocumentBulkUploads::class)->markFailed($this->batchId, 'The upload stopped: '.($e?->getMessage() ?? 'unknown error')));
        }
    }
}
