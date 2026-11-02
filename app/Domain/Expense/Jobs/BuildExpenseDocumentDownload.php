<?php

namespace App\Domain\Expense\Jobs;

use App\Domain\Expense\Models\ExpenseDocumentDownload;
use App\Domain\Expense\Services\ExpenseDocumentDownloads;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** HROne parity XO-3: prepares one bulk expense documents download (ExpenseDocumentDownloads::build) in the tenant it was asked in. */
class BuildExpenseDocumentDownload implements ShouldQueue, TenantAwareJob
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public ?int $tenantId;

    public function __construct(public readonly int $downloadId)
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

    public function handle(ExpenseDocumentDownloads $downloads): void
    {
        $download = ExpenseDocumentDownload::query()->find($this->downloadId);
        if ($download !== null) {
            // A retry after a crash finds it running: start again from the list.
            if ($download->status === 'running' && $this->attempts() > 1) {
                $download->withoutAuditing()->update(['status' => 'queued']);
            }
            $downloads->build($download);
        }
    }

    public function failed(?Throwable $e): void
    {
        $tenant = $this->tenantId === null ? null : Tenant::query()->find($this->tenantId);
        if ($tenant !== null) {
            app(TenantContext::class)->runAs($tenant, fn () => app(ExpenseDocumentDownloads::class)->markFailed($this->downloadId, 'The download could not be prepared: '.($e?->getMessage() ?? 'unknown error')));
        }
    }
}
